[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[^@\s]+@[^@\s]+\.[^@\s]+$')]
    [string]$MasterEmail,

    [string]$ConfigPath = 'ops/release-config.local.json',

    [string]$AuditDate = (Get-Date -Format 'yyyy-MM-dd'),

    [ValidateRange(0, [int]::MaxValue)]
    [int]$ExpectedLivePatientsTotal = 0,

    [ValidateSet('Markdown', 'PlanJson')]
    [string]$OutputFormat = 'Markdown'
)

$ErrorActionPreference = 'Stop'

function Resolve-RepoPath {
    param([Parameter(Mandatory = $true)][string]$Path)

    if ([System.IO.Path]::IsPathRooted($Path)) {
        return [System.IO.Path]::GetFullPath($Path)
    }

    $repoRoot = Split-Path -Parent $PSScriptRoot
    return [System.IO.Path]::GetFullPath((Join-Path $repoRoot $Path))
}

function Invoke-CoolifyApi {
    param(
        [Parameter(Mandatory = $true)][string]$Path
    )

    return Invoke-RestMethod `
        -Method Get `
        -Uri ($script:CoolifyBaseUrl.TrimEnd('/') + $Path) `
        -Headers @{
            Authorization = "Bearer $script:CoolifyToken"
            Accept = 'application/json'
        }
}

function Find-RuntimeEnvValue {
    param(
        [Parameter(Mandatory = $true)][object[]]$Variables,
        [Parameter(Mandatory = $true)][string]$Key
    )

    foreach ($candidate in $Variables) {
        $items = if ($candidate -is [System.Array]) { $candidate } else { @($candidate) }
        foreach ($variable in $items) {
            if (([string]$variable.key) -ne $Key -or [bool]$variable.is_preview) {
                continue
            }

            if (-not [string]::IsNullOrWhiteSpace([string]$variable.real_value)) {
                return [string]$variable.real_value
            }

            return [string]$variable.value
        }
    }

    return ''
}

$resolvedConfigPath = Resolve-RepoPath -Path $ConfigPath
$auditScriptPath = Resolve-RepoPath -Path 'ops/audit-patient-duplicates.php'
$config = Get-Content -LiteralPath $resolvedConfigPath -Raw | ConvertFrom-Json

$script:CoolifyBaseUrl = [string]$config.coolifyBaseUrl
$script:CoolifyToken = [string]$config.coolifyToken
$testDatabaseUuid = [string]$config.dbRefresh.testDatabaseUuid
$loginApplicationUuid = [string]$config.targets.login.appUuid

if ([string]::IsNullOrWhiteSpace($script:CoolifyBaseUrl) `
    -or [string]::IsNullOrWhiteSpace($script:CoolifyToken) `
    -or [string]::IsNullOrWhiteSpace($testDatabaseUuid) `
    -or [string]::IsNullOrWhiteSpace($loginApplicationUuid)) {
    throw 'Configurazione Coolify incompleta.'
}

$database = Invoke-CoolifyApi -Path "/api/v1/databases/$testDatabaseUuid"
$externalUri = [Uri]([string]$database.external_db_url)
$environmentVariables = @(Invoke-CoolifyApi -Path "/api/v1/applications/$loginApplicationUuid/envs")
$encryptionKey = Find-RuntimeEnvValue `
    -Variables $environmentVariables `
    -Key 'DB_ENCRYPTION_KEY'

if ([string]::IsNullOrWhiteSpace($encryptionKey)) {
    throw 'Chiave di cifratura del database non disponibile.'
}

$auditEnvironment = @{
    AUDIT_DB_HOST = $externalUri.Host
    AUDIT_DB_PORT = [string]$externalUri.Port
    AUDIT_DB_USER = 'root'
    AUDIT_DB_PASSWORD = [string]$database.mysql_root_password
    AUDIT_PLATFORM_DATABASE = 'ambulatoriofacile_login'
    AUDIT_DB_ENCRYPTION_KEY = $encryptionKey
    AUDIT_DB_ENCRYPTION_MODE = 'aes-256-cbc'
}
$previousEnvironment = @{}

try {
    foreach ($name in $auditEnvironment.Keys) {
        $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $auditEnvironment[$name], 'Process')
    }

    $rawResult = & php `
        -d xdebug.mode=off `
        $auditScriptPath `
        "--master-email=$($MasterEmail.Trim().ToLowerInvariant())" 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ($rawResult -join [Environment]::NewLine)
    }

    $result = ($rawResult -join [Environment]::NewLine) | ConvertFrom-Json
    $tenant = $result.tenants
    $groups = @($tenant.groups)

    if ($OutputFormat -eq 'PlanJson') {
        if ($ExpectedLivePatientsTotal -le 0) {
            throw 'ExpectedLivePatientsTotal deve essere indicato per generare un piano destinato al live.'
        }

        $planGroups = foreach ($group in $groups) {
            [ordered]@{
                display_name = [string]$group.display_name
                target_id = [int]$group.decision.target_id
                source_ids = @($group.decision.source_ids | ForEach-Object { [int]$_ })
                classification = [string]$group.decision.classification
                reason = [string]$group.decision.reason
                members = @($group.members | ForEach-Object {
                    [ordered]@{
                        id_client = [int]$_.id_client
                        nome = [string]$_.nome
                        cognome = [string]$_.cognome
                        profile_fields_present = @($_.profile_fields_present)
                    }
                })
            }
        }

        [ordered]@{
            plan_version = 1
            generated_at = $AuditDate
            mode = 'PREPARED_NOT_APPLIED'
            master_email = $MasterEmail.Trim().ToLowerInvariant()
            tenant_key = [string]$tenant.tenant.tenant_key
            tenant_database = [string]$tenant.tenant.database
            expected_live_patients_total = $ExpectedLivePatientsTotal
            expected_duplicate_groups = [int]$tenant.summary.duplicate_name_groups
            expected_duplicate_records = [int]$tenant.summary.duplicate_records_total
            expected_source_records = [int]$tenant.summary.records_auto_mergeable
            groups = @($planGroups)
        } | ConvertTo-Json -Depth 12
        return
    }

    $bareGroups = 0
    $richTargetGroups = 0
    $appointmentsToMove = 0
    $lines = [System.Collections.Generic.List[string]]::new()

    $lines.Add("# Audit doppioni pazienti - $([string]$tenant.tenant.tenant_name)")
    $lines.Add('')
    $lines.Add("Data audit: $AuditDate")
    $lines.Add('')
    $lines.Add('Modalità: sola lettura. Nessuna anagrafica, associazione o appuntamento è stato modificato.')
    $lines.Add('')
    $lines.Add('## Riscontro')
    $lines.Add('')
    $lines.Add("- Master verificato: $($MasterEmail.Trim().ToLowerInvariant())")
    $lines.Add("- Tenant: $([string]$tenant.tenant.tenant_name) ($([string]$tenant.tenant.database))")
    $lines.Add("- Copia analizzata: $([int]$tenant.summary.patients_total) pazienti")
    $lines.Add("- Doppioni rilevati: $([int]$tenant.summary.duplicate_name_groups) gruppi, $([int]$tenant.summary.duplicate_records_total) schede coinvolte")
    $lines.Add("- Schede eliminabili dopo fusione: $([int]$tenant.summary.records_auto_mergeable)")
    $lines.Add("- Gruppi da mantenere separati per conflitti identificativi: $([int]$tenant.summary.groups_keep_separate)")
    $lines.Add("- Gruppi da sottoporre a revisione manuale: $([int]$tenant.summary.groups_manual_review)")
    $lines.Add('')
    $lines.Add('## Operazione prevista dopo autorizzazione')
    $lines.Add('')
    $lines.Add("Per ogni gruppo, gli appuntamenti e tutti i riferimenti della scheda sorgente dovranno essere riassegnati alla scheda mantenuta. Solo dopo la verifica dei conteggi si potrà eliminare la scheda sorgente, il tutto in un'unica transazione con rollback in caso di anomalia.")
    $lines.Add('')
    $lines.Add('## Elenco completo')
    $lines.Add('')

    foreach ($group in $groups) {
        $decision = $group.decision
        $sourceIds = @($decision.source_ids | ForEach-Object { [int]$_ })
        $targetId = [int]$decision.target_id
        $target = $null
        foreach ($member in @($group.members)) {
            if ([int]$member.id_client -eq $targetId) {
                $target = $member
                break
            }
        }

        $sourceAppointments = 0
        foreach ($member in @($group.members)) {
            if ($sourceIds -contains [int]$member.id_client) {
                $appointmentCount = $member.references.dap12_agenda_appuntamenti
                if ($null -ne $appointmentCount) {
                    $sourceAppointments += [int]$appointmentCount
                }
            }
        }
        $appointmentsToMove += $sourceAppointments

        $sourceLabel = (($sourceIds | ForEach-Object { "#$_" }) -join ', ')
        $fields = if ($null -ne $target) { @($target.profile_fields_present) } else { @() }
        if (([string]$decision.reason) -like 'Tutti*') {
            $bareGroups++
            $detail = 'tutte le schede hanno solo nome e cognome'
        } else {
            $richTargetGroups++
            $detail = if ($fields.Count -gt 0) {
                'dati presenti sul mantenuto: ' + ($fields -join ', ')
            } else {
                'mantenuto con dati ulteriori'
            }
        }

        $groupLine = "- $([string]$group.display_name) - mantiene #$targetId; " `
            + "confluiscono $sourceLabel; appuntamenti da spostare: $sourceAppointments; $detail."
        $lines.Add($groupLine)
    }

    $lines.Add('')
    $lines.Add('## Totali operativi')
    $lines.Add('')
    $lines.Add("- Gruppi composti soltanto da schede nome/cognome: $bareGroups")
    $lines.Add("- Gruppi con una sola scheda più completa da mantenere: $richTargetGroups")
    $lines.Add("- Schede sorgente da eliminare dopo riassegnazione: $([int]$tenant.summary.records_auto_mergeable)")
    $lines.Add("- Appuntamenti delle schede sorgente rilevati: $appointmentsToMove")
    $lines.Add('')
    $lines.Add("## Controlli obbligatori prima dell'esecuzione")
    $lines.Add('')
    $lines.Add('- Rifare lo stesso audit sul live immediatamente prima della fusione e fermarsi se i conteggi o le identità non coincidono.')
    $lines.Add("- Aggiornare sia id_client sia l'eventuale riferimento legacy id_paziente negli appuntamenti.")
    $lines.Add('- Riassegnare tutte le altre tabelle che referenziano id_client, evitando duplicati nelle associazioni medico-paziente.')
    $lines.Add('- Verificare che ogni sorgente abbia zero riferimenti prima di eliminarla.')
    $lines.Add('- Eseguire tutto in transazione e produrre un verbale finale con conteggi prima/dopo.')

    $referenceErrors = @($tenant.reference_scan_errors | Where-Object { $null -ne $_ })
    if ($referenceErrors.Count -gt 0) {
        $lines.Add('')
        $lines.Add("Nota tecnica: una relazione non essenziale non è stata conteggiata automaticamente nella copia test; dovrà essere ricontrollata nel preflight live prima dell'esecuzione.")
    }

    Write-Output ($lines -join [Environment]::NewLine)
} finally {
    foreach ($name in $previousEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
    }
}
