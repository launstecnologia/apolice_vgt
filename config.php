<?php

return [
    // Exemplo: '/apolice/public'
    // Deixe vazio para detecção automática.
    'base_url' => '',
    'storage_path' => __DIR__ . '/storage',
    // Salva o texto extraído do PDF para depuração.
    'debug_extract_path' => __DIR__ . '/storage/data/pdf_extract.txt',
    'template_path' => __DIR__ . '/templates/apolice_base.docx',
    'template_html_path' => __DIR__ . '/2cad4251-fd2d-4d06-a0c1-3c29c689843d.html',
    'storage_docx_path' => __DIR__ . '/storage/docx',
    'storage_html_path' => __DIR__ . '/storage/html',
    'storage_pdf_path' => __DIR__ . '/storage/pdf',
    // Caminho do LibreOffice (ex: /usr/bin/soffice). Vazio usa "soffice".
    'libreoffice_path' => '',
    'logo_path' => __DIR__ . '/public/logo_mafre.jpg',
    // 'auto' usa HTML se existir, senão DOCX.
    'pdf_engine' => 'html',
];
