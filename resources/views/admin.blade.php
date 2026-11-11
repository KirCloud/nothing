<!DOCTYPE html>
<html>

<head>
    <link rel="stylesheet" href="/assets/admin/components.chunk.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/umi.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/custom.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/google-oauth-settings.css?v={{ filemtime(public_path('assets/admin/google-oauth-settings.css')) }}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body>
<div id="root"></div>
<script src="/assets/admin/vendors.async.js?v={{$version}}"></script>
<script src="/assets/admin/components.async.js?v={{$version}}"></script>
<script src="/assets/admin/umi.js?v={{$version}}&auth={{ filemtime(public_path('assets/admin/umi.js')) }}"></script>
<script src="/assets/admin/google-oauth-settings.js?v={{ filemtime(public_path('assets/admin/google-oauth-settings.js')) }}"></script>
</body>

</html>
