<?php
// Pure fixture selection: no DB access, useful for both manual and automated labs.
function fse_lab_revision_pair(array $documents): array
{
    $byId=[]; $pairs=[];
    foreach ($documents as $row) {
        $id=(int)($row['id_fse_document']??0);
        if ($id<=0 || isset($byId[$id])) throw new RuntimeException('Invalid synthetic document identity.');
        $byId[$id]=$row;
    }
    foreach ($byId as $id=>$revision) {
        if ((int)($revision['version_number']??0)!==2) continue;
        $parent=(int)($revision['previous_document_id']??0);
        $original=$byId[$parent]??null;
        if (!$original || (int)($original['version_number']??0)!==1 || ($original['local_state']??'')!=='signed') continue;
        if (empty($original['set_id']) || $original['set_id']!==($revision['set_id']??null)
            || empty($original['profile_snapshot_json']) || $original['profile_snapshot_json']!==($revision['profile_snapshot_json']??null)) {
            throw new RuntimeException('Broken synthetic version chain.');
        }
        $pairs[]=[$parent,$id];
    }
    if (count($pairs)!==1) throw new RuntimeException('One unambiguous signed original/revision fixture is required.');
    return $pairs[0];
}
