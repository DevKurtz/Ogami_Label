<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Ogami Label Studio</title>
    <link rel="icon" type="image/svg+xml" href="/ogami-mark.svg">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto flex min-h-screen max-w-md items-center px-5 py-12">
        <section class="w-full rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <div class="flex items-center gap-3"><img src="/ogami-mark.svg" alt="" class="h-11 w-11"><p class="text-lg font-extrabold">OGAMI <span class="text-blue-600">LABEL STUDIO</span></p></div>
            <h1 class="mt-8 text-2xl font-bold">Sign in to continue</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Sign in with your Ogami username and password to access workbooks and label history.</p>

            @if ($errors->any())
                <div role="alert" class="mt-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.submit') }}" class="mt-7 space-y-4">
                @csrf
                <div>
                    <label for="username" class="mb-1.5 block text-sm font-semibold text-slate-700">Username</label>
                    <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus maxlength="80" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" placeholder="Your Ogami username">
                </div>
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-semibold text-slate-700">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" placeholder="Your password">
                </div>
                <button type="submit" class="w-full rounded-lg bg-blue-700 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">Sign in</button>
            </form>

            <p class="mt-6 text-xs leading-5 text-slate-500">Access is limited to accounts set up by an Ogami administrator. Each account can view and delete only the workbooks it uploaded.</p>
        </section>
    </main>
</body>
</html>
