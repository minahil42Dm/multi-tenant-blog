<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <title>Starting impersonation</title>
    </head>
    <body style="font-family: sans-serif; padding: 2rem;">
        <form id="impersonate" method="POST" action="{{ $action }}">
            <input type="hidden" name="token" value="{{ $token }}">
            <p>Signing in as {{ $targetEmail }}&hellip;</p>
            <noscript><button type="submit">Continue</button></noscript>
        </form>
        <script>document.getElementById('impersonate').submit();</script>
    </body>
</html>
