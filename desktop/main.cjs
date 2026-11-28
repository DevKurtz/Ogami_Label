const { app, BrowserWindow, dialog } = require('electron');
const { spawn } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const { existsSync, mkdirSync, readFileSync, writeFileSync } = require('node:fs');
const path = require('node:path');

let server;
let window;

function locations() {
    const packaged = app.isPackaged;
    const resources = process.resourcesPath;
    return {
        project: packaged ? path.join(resources, 'app') : path.resolve(__dirname, '..'),
        php: process.env.OGAMI_PHP_PATH || (packaged
            ? path.join(resources, 'php', 'php.exe')
            : path.resolve(__dirname, 'runtime', 'php', 'php.exe')),
        data: path.join(app.getPath('userData'), 'data'),
        config: path.join(app.getPath('userData'), 'desktop.json'),
    };
}

function localEnvironment(paths, port, key) {
    return {
        ...process.env,
        APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: `http://127.0.0.1:${port}`,
        APP_KEY: key, LOCAL_DESKTOP: 'true',
        DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(paths.data, 'ogami.sqlite'),
        SESSION_DRIVER: 'file', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync',
        LARAVEL_STORAGE_PATH: path.join(paths.data, 'storage'),
    };
}

function getKey(configPath) {
    let config = {};
    try { config = JSON.parse(readFileSync(configPath, 'utf8')); } catch {}
    config.appKey ||= `base64:${randomBytes(32).toString('base64')}`;
    mkdirSync(path.dirname(configPath), { recursive: true });
    writeFileSync(configPath, JSON.stringify(config, null, 2), { mode: 0o600 });
    return config.appKey;
}

function runPhp(php, args, cwd, env) {
    return new Promise((resolve, reject) => {
        const phpOptions = ['-d', `extension_dir=${path.join(path.dirname(php), 'ext')}`];
        const child = spawn(php, [...phpOptions, ...args], { cwd, env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
        let output = '';
        child.stdout.on('data', chunk => { output += chunk.toString(); });
        child.stderr.on('data', chunk => { output += chunk.toString(); });
        child.once('error', reject);
        child.once('close', code => code === 0 ? resolve(output) : reject(new Error(output || `PHP exited with code ${code}.`)));
    });
}

async function startLocalApp() {
    const paths = locations();
    if (!existsSync(paths.php)) throw new Error('The desktop PHP runtime is missing. Install the complete desktop package or configure OGAMI_PHP_PATH.');
    if (!existsSync(path.join(paths.project, 'artisan')) || !existsSync(path.join(paths.project, 'vendor', 'autoload.php'))) {
        throw new Error('The local application files are incomplete. Reinstall Ogami Label Studio.');
    }
    mkdirSync(paths.data, { recursive: true });
    for (const directory of [
        'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
        'storage/framework/sessions', 'storage/framework/views', 'storage/logs',
    ]) mkdirSync(path.join(paths.data, directory), { recursive: true });

    const net = require('node:net');
    const port = await new Promise((resolve, reject) => {
        const probe = net.createServer();
        probe.once('error', reject);
        probe.listen(0, '127.0.0.1', () => {
            const { port: selectedPort } = probe.address();
            probe.close(() => resolve(selectedPort));
        });
    });
    const env = localEnvironment(paths, port, getKey(paths.config));
    await runPhp(paths.php, ['artisan', 'migrate', '--force', '--no-interaction'], paths.project, env);
    server = spawn(paths.php, ['-d', `extension_dir=${path.join(path.dirname(paths.php), 'ext')}`, 'artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
        cwd: paths.project, env, windowsHide: true, stdio: 'ignore',
    });
    server.once('error', error => dialog.showErrorBox('Ogami Label Studio could not start', error.message));

    const url = `http://127.0.0.1:${port}`;
    for (let attempt = 0; attempt < 60; attempt++) {
        if (server.exitCode !== null) throw new Error('The local app stopped while starting. Restart it and try again.');
        try { const response = await fetch(`${url}/up`); if (response.ok) return url; } catch {}
        await new Promise(resolve => setTimeout(resolve, 250));
    }
    throw new Error('Ogami Label Studio did not become ready in time.');
}

async function createWindow() {
    try {
        const url = await startLocalApp();
        window = new BrowserWindow({
            width: 1440, height: 960, minWidth: 1040, minHeight: 700,
            backgroundColor: '#f1f5f9', title: 'Ogami Label Studio',
            icon: app.isPackaged
                ? path.join(process.resourcesPath, 'ogami-icon.ico')
                : path.join(__dirname, 'assets', 'ogami-icon.ico'),
            webPreferences: { contextIsolation: true, nodeIntegration: false, sandbox: true },
        });
        window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
        await window.loadURL(url);
    } catch (error) {
        dialog.showErrorBox('Ogami Label Studio could not start', error.message);
        app.quit();
    }
}

app.whenReady().then(createWindow);
app.on('window-all-closed', () => app.quit());
app.on('before-quit', () => { if (server && server.exitCode === null) server.kill(); });
