<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt - {{ config('app.name', 'Grade A Paint Center') }}</title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            body {
                background: white !important;
                color: black !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .no-print, .print\:hidden {
                display: none !important;
            }
            .print-receipt-container {
                box-shadow: none !important;
                border: none !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            @page {
                margin: 10mm;
                size: auto;
            }
        }
    </style>
</head>
<body class="font-sans antialiased bg-gray-100 text-gray-900 min-h-screen py-6 px-4 print:p-0 print:bg-white">
    <div class="mx-auto max-w-2xl">
        {{ $slot }}
    </div>
</body>
</html>
