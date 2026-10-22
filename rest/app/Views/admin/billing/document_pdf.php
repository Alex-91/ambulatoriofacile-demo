<?php
// All invoices use the current editable model, including historical documents.
echo view('admin/billing/designer_document', ['preview'=>$preview, 'pdfMode'=>true]);
