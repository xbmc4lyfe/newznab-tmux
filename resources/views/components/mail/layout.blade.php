@props([
    'title' => null,
    'preheader' => null,
    'siteName' => null,
])
@php
    $brandName = $siteName ?? config('app.name');
    $brandUrl = config('app.url');
    $logoUrl = config('mail.brand.logo_url');
    $renderedTitle = $title ?? $brandName;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $renderedTitle }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #334155;
            background-color: #f8fafc;
            padding: 20px;
            -webkit-text-size-adjust: 100%;
        }
        .preheader {
            display: none !important;
            visibility: hidden;
            opacity: 0;
            color: transparent;
            height: 0;
            width: 0;
            overflow: hidden;
            mso-hide: all;
        }
        .email-wrapper {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.06), 0 1px 2px -1px rgba(15, 23, 42, 0.06);
        }
        .email-header {
            background-color: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            color: #0f172a;
            padding: 28px 40px;
            text-align: center;
        }
        .email-header h1 {
            font-size: 22px;
            font-weight: 700;
            margin: 0;
            color: #0f172a;
            letter-spacing: -0.01em;
        }
        .email-header .brand {
            display: inline-block;
            font-size: 14px;
            font-weight: 600;
            color: #2563eb;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
            text-decoration: none;
        }
        .email-header .logo { height: 56px; width: auto; }
        .email-body { padding: 36px 40px; }
        .email-content { font-size: 16px; color: #334155; }
        .email-content p { margin-bottom: 16px; }
        .email-content h2 { color: #0f172a; font-size: 18px; font-weight: 600; margin: 24px 0 12px; }
        .email-content h3 { color: #0f172a; font-size: 15px; font-weight: 600; margin: 20px 0 10px; }
        .greeting { font-size: 17px; color: #0f172a; font-weight: 500; margin-bottom: 18px; }
        .signature {
            margin-top: 32px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 14px;
        }
        .button {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 26px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 15px;
            margin: 16px 0;
            mso-padding-alt: 0;
            line-height: 1.4;
        }
        .button-success { background-color: #16a34a; }
        .button-danger { background-color: #dc2626; }
        .button-secondary { background-color: #475569; }
        .info-box {
            background-color: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 16px 20px;
            margin: 20px 0;
            border-radius: 0 6px 6px 0;
            color: #1e3a8a;
        }
        .warning-box {
            background-color: #fff7ed;
            border-left: 4px solid #f59e0b;
            padding: 16px 20px;
            margin: 20px 0;
            border-radius: 0 6px 6px 0;
            color: #92400e;
        }
        .alert-box {
            padding: 14px 18px;
            margin: 20px 0;
            border-radius: 6px;
            font-size: 15px;
            line-height: 1.5;
        }
        .alert-info { background-color: #eff6ff; border-left: 4px solid #2563eb; color: #1e3a8a; }
        .alert-success { background-color: #f0fdf4; border-left: 4px solid #16a34a; color: #166534; }
        .alert-warning { background-color: #fff7ed; border-left: 4px solid #f59e0b; color: #92400e; }
        .alert-danger { background-color: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }
        .link-text {
            word-break: break-all;
            background-color: #f1f5f9;
            padding: 12px 16px;
            border-radius: 6px;
            font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 13px;
            display: block;
            margin: 10px 0;
            color: #1e293b;
        }
        .status-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .status-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
            color: #334155;
            vertical-align: top;
        }
        .status-table .status-label {
            font-weight: 600;
            color: #0f172a;
            width: 150px;
            white-space: nowrap;
        }
        .email-footer {
            background-color: #f8fafc;
            padding: 22px 40px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }
        .email-footer p { margin: 4px 0; line-height: 1.6; }
        .email-footer a { color: #64748b; text-decoration: underline; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        @media only screen and (max-width: 600px) {
            body { padding: 0; }
            .email-wrapper { border-radius: 0; border-left: 0; border-right: 0; }
            .email-header, .email-body, .email-footer { padding-left: 24px; padding-right: 24px; }
            .button { display: block; text-align: center; }
        }
    </style>
</head>
<body>
    @if ($preheader)
        <span class="preheader">{{ $preheader }}</span>
    @endif
    <div class="email-wrapper">
        @isset($header)
            {{ $header }}
        @else
            <div class="email-header">
                <a href="{{ $brandUrl }}" class="brand">{{ $brandName }}</a>
                @if ($logoUrl)
                    <div><img src="{{ $logoUrl }}" alt="{{ $brandName }}" class="logo"></div>
                @endif
                @if ($title)
                    <h1>{{ $title }}</h1>
                @endif
            </div>
        @endisset

        <div class="email-body">
            <div class="email-content">
                {{ $slot }}
            </div>
        </div>

        @isset($footer)
            {{ $footer }}
        @else
            <div class="email-footer">
                <p>&copy; {{ date('Y') }} {{ $brandName }}. {{ __('All rights reserved.') }}</p>
                <p>{{ __('This is an automated message. Please do not reply directly to this email.') }}</p>
                @if (\Illuminate\Support\Facades\Route::has('contact-us'))
                    <p><a href="{{ url('/contact-us') }}">{{ __('Contact us') }}</a></p>
                @endif
            </div>
        @endisset
    </div>
</body>
</html>
