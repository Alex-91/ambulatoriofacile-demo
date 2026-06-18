<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Permessi menu ruoli | Agenda</title>
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

    <style>
        .menu-role-tree {
            border: 1px solid #d2d6de;
            border-radius: 4px;
            padding: 15px;
            background: #fff;
            min-height: 200px;
            max-height: 650px;
            overflow-y: auto;
        }

        .menu-node {
            margin-bottom: 8px;
        }

        .menu-node-children {
            margin-left: 25px;
            padding-left: 15px;
            border-left: 2px solid #f0f0f0;
            margin-top: 8px;
        }

        .menu-node-label {
            font-weight: 600;
        }

        .menu-node-item {
            font-weight: 400;
        }

        .loading-box {
            text-align: center;
            color: #666;
            padding: 20px 0;
        }

        .toolbar-actions {
            margin-top: 25px;
            text-align: right;
        }

        .toolbar-actions .btn {
            margin-left: 5px;
        }
    </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">

    <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

    <aside class="main-sidebar" style="display:none">
        <section class="sidebar"></section>
    </aside>

    <div class="content-wrapper">
        <section class="content-header">
            <h1>Permessi menu ruoli</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Permessi menu ruoli</li>
            </ol>
        </section>

        <section class="content">
            <div class="row">
                <div class="col-md-3">
                    <div class="box box-solid">
                        <div class="box-header with-border">
                            <h3 class="box-title">Menu</h3>
                        </div>
                        <div class="box-body no-padding">
                            <?= view('agenda/partials/menu_laterale', ['menuAgenda' => $menuAgenda ?? []]) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-9">
                    <div class="box box-primary">
                        <div class="box-header with-border">
                            <h3 class="box-title">
                                <i class="fa fa-key"></i> Configurazione permessi menu per ruolo
                            </h3>
                        </div>

                        <div class="box-body">
                            <div class="row">
                                <div class="col-md-5 form-group">
                                    <label for="id_ruo">Ruolo</label>
                                    <select id="id_ruo" class="form-control">
                                        <?php foreach (($ruoli ?? []) as $ruolo): ?>
                                            <option value="<?= (int)$ruolo['id_ruo'] ?>"
                                                <?= ((int)($selectedRuo ?? 0) === (int)$ruolo['id_ruo']) ? 'selected' : '' ?>>
                                                <?= esc($ruolo['des_ruo']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-7 toolbar-actions">
                                    <button type="button" class="btn btn-default" id="btnCheckAll">
                                        <i class="fa fa-check-square-o"></i> Seleziona tutto
                                    </button>
                                    <button type="button" class="btn btn-default" id="btnUncheckAll">
                                        <i class="fa fa-square-o"></i> Deseleziona tutto
                                    </button>
                                    <button type="button" class="btn btn-primary" id="btnSavePermessi">
                                        <i class="fa fa-save"></i> Salva
                                    </button>
                                </div>
                            </div>

                            <div id="menuTreeBox" class="menu-role-tree">
                                <div class="loading-box">
                                    <i class="fa fa-spinner fa-spin"></i> Caricamento...
                                </div>
                            </div>

                            <div class="alert alert-info" style="margin-top:15px; margin-bottom:0;">
                                La schermata gestisce sia voci principali che sottomenu.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>

<script>
function escapeHtml(text) {
    return $('<div>').text(text == null ? '' : text).html();
}

function renderTree(nodes) {
    if (!nodes || !nodes.length) {
        return '<div class="loading-box">Nessuna voce di menu trovata.</div>';
    }

    var html = '';

    $.each(nodes, function(index, node) {
        var isMenu = node.tipo_voce === 'MENU';

        html += '<div class="menu-node">';
        html += '   <label>';
        html += '       <input type="checkbox" class="chk-menu" value="' + node.id_menu + '" ' + (parseInt(node.checked, 10) === 1 ? 'checked' : '') + '>';
        html += '       <span class="' + (isMenu ? 'menu-node-label' : 'menu-node-item') + '">';
        html +=             (node.icona ? '<i class="' + escapeHtml(node.icona) + '"></i> ' : '');
        html +=             escapeHtml(node.label_menu);
        html += '       </span>';
        if (node.rotta) {
            html += '   <small class="text-muted" style="margin-left:8px;">' + escapeHtml(node.rotta) + '</small>';
        }
        html += '   </label>';

        if (node.children && node.children.length) {
            html += '<div class="menu-node-children">';
            html += renderTree(node.children);
            html += '</div>';
        }

        html += '</div>';
    });

    return html;
}

function caricaPermessiRuolo() {
    $('#menuTreeBox').html('<div class="loading-box"><i class="fa fa-spinner fa-spin"></i> Caricamento...</div>');

    $.get("<?= base_url('agenda/menu-ruoli-dati') ?>", {
        id_ruo: $('#id_ruo').val()
    }, function(res) {
        if (!res.status) {
            $('#menuTreeBox').html('<div class="loading-box text-danger">' + escapeHtml(res.message || 'Errore nel caricamento') + '</div>');
            return;
        }

        $('#menuTreeBox').html(renderTree(res.rows));
    }, 'json');
}

$(function () {
    caricaPermessiRuolo();

    $('#id_ruo').on('change', function () {
        caricaPermessiRuolo();
    });

    $('#btnCheckAll').on('click', function () {
        $('#menuTreeBox .chk-menu').prop('checked', true);
    });

    $('#btnUncheckAll').on('click', function () {
        $('#menuTreeBox .chk-menu').prop('checked', false);
    });

    $(document).on('change', '.chk-menu', function () {
        var checked = $(this).is(':checked');

        $(this).closest('.menu-node')
            .find('.menu-node-children .chk-menu')
            .prop('checked', checked);
    });

    $('#btnSavePermessi').on('click', function () {
        var ids = [];

        $('#menuTreeBox .chk-menu:checked').each(function () {
            ids.push($(this).val());
        });

        $.post("<?= base_url('agenda/salva-menu-ruoli') ?>", {
            id_ruo: $('#id_ruo').val(),
            id_menu: ids
        }, function(res) {
            alert(res.message || 'Operazione completata');
        }, 'json');
    });
});
</script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>