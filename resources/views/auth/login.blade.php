<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Ogami Label Studio</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto flex min-h-screen max-w-md items-center px-5 py-12">
        <section class="w-full rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <p class="text-lg font-extrabold">OGAMI <span class="text-blue-600">LABEL STUDIO</span></p>
            <h1 class="mt-8 text-2xl font-bold">Sign in to continue</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Use your Google account to upload workbooks and view label history.</p>

            @if ($errors->any())
                <div role="alert" class="mt-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    {{ $errors->first() }}
                </div>
            @endif

            @if ($googleConfigured)
                <a href="{{ route('auth.google') }}" class="mt-7 flex w-full items-center justify-center gap-3 rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50">
                    <svg aria-hidden="true" viewBox="0 0 48 48" class="h-5 w-5"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5Z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.91c-.58 2.96-2.26 5.48-4.73 7.18l7.64 5.93c4.46-4.12 7.16-10.2 7.16-17.58Z"/><path fill="#FBBC05" d="M10.53 28.59A14.4 14.4 0 0 1 9.75 24c0-1.59.27-3.13.75-4.59l-7.98-6.2A23.9 23.9 0 0 0 0 24c0 3.88.93 7.55 2.56 10.78l7.97-6.19Z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.9-5.87l-7.64-5.93c-2.12 1.42-4.84 2.27-8.26 2.27-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 43.62 14.62 48 24 48Z"/></svg>
                    Continue with Google
                </a>
            @else
                <p class="mt-7 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Google sign-in is not configured yet. Add the Google OAuth values to the Vercel project to enable access.</p>
            @endif

            <p class="mt-6 text-xs leading-5 text-slate-500">Only verified Google accounts can sign in. Each account can view and delete only the workbooks it uploaded.</p>
        </section>
    </main>
</body>
</html>
