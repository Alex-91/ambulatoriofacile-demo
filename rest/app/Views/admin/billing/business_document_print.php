<?php
echo view('admin/billing/designer_document', ['preview'=>$preview, 'pdfMode'=>empty($showToolbar), 'showToolbar'=>!empty($showToolbar)]);
