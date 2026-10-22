<?php
if (!empty($preview['managed_collections'])) { echo view('admin/polyclinic/document_pdf',['preview'=>$preview]); return; }
if (($preview['template']['recipient_type'] ?? '') === 'business') {
    echo view('admin/billing/business_document_print', ['preview' => $preview]);
    return;
}
// Editor, preview and PDF share the same default and saved model.
echo view('admin/billing/designer_document', ['preview'=>$preview, 'pdfMode'=>true]);
