<script setup>
import { computed, onMounted, ref } from 'vue';

const batches = ref([]);
const selectedBatch = ref(null);
const selectedRecord = ref(null);
const file = ref(null);
const fileInput = ref(null);
const batchName = ref('');
const query = ref('');
const loading = ref(false);
const error = ref('');
const success = ref(null);
const activePage = ref('upload');
const errorLines = computed(() => error.value.split('\n').filter(Boolean));
const errorSummary = computed(() => {
    const missing = errorLines.value.map((line) => line.match(/^Row (\d+): (.+) is blank\.$/));
    if (missing.length > 1 && missing.every(Boolean)) {
        const fields = [...new Set(missing.map((match) => match[2]))];
        if (fields.length === 1) {
            const field = fields[0];
            const column = field === 'manufacturing date' ? 'MFG. DATE' : field;
            return `${missing.length} rows are missing ${field}. Fill in the “${column}” column for those rows, then upload again.`;
        }
    }
    return errorLines.value.length > 1
        ? `${errorLines.value.length} validation issues found. Review the details before uploading again.`
        : (errorLines.value[0] ?? 'Please review the workbook and try again.');
});

const visibleBatches = computed(() => batches.value.filter((batch) => {
    const term = query.value.trim().toLowerCase();
    if (!term) return true;
    return batch.name.toLowerCase().includes(term) || batch.original_filename.toLowerCase().includes(term)
        || batch.records?.some((record) => JSON.stringify(record.data).toLowerCase().includes(term));
}));
const records = computed(() => selectedBatch.value?.records ?? []);
const recordIndex = computed(() => records.value.findIndex((record) => record.id === selectedRecord.value?.id));

async function request(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '', ...options.headers } });
    const body = await response.json();
    if (!response.ok) throw new Error(Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? 'Something went wrong. Please try again.');
    return body;
}
async function refreshBatches() {
    batches.value = await request('/api/batches');
}
async function upload() {
    if (!file.value) return;
    loading.value = true; error.value = ''; success.value = null;
    const form = new FormData(); form.append('file', file.value); form.append('name', batchName.value || file.value.name);
    try {
        const saved = await request('/api/batches', { method: 'POST', body: form });
        await refreshBatches();
        selectedBatch.value = saved;
        selectedRecord.value = saved.records?.[0] ?? null;
        success.value = { name: saved.name, filename: saved.original_filename, count: saved.row_count };
        file.value = null;
        batchName.value = '';
        if (fileInput.value) fileInput.value.value = '';
        activePage.value = 'record';
    } catch (exception) { error.value = exception.message; }
    finally { loading.value = false; }
}
function onFileChange(event) {
    error.value = '';
    success.value = null;
    file.value = event.target.files?.[0] ?? null;
    if (file.value && file.value.size > 4 * 1024 * 1024) {
        error.value = 'This file is larger than 4 MB. Choose a smaller Excel file.';
        file.value = null;
        event.target.value = '';
    }
}
function clearFile(input) {
    file.value = null;
    error.value = '';
    success.value = null;
    if (input) input.value = '';
}
async function openBatch(id) {
    try { selectedBatch.value = await request(`/api/batches/${id}`); }
    catch (exception) { error.value = exception.message; }
}
function selectRecord(record) { selectedRecord.value = record; activePage.value = 'record'; }
function moveRecord(step) {
    const next = records.value[recordIndex.value + step]; if (next) selectedRecord.value = next;
}
function labelUrl(record) { return `/api/records/${record.id}/label.svg`; }
function codeUrl(record, key) { return `/api/records/${record.id}/label.svg#${key}`; }

onMounted(async () => { try { await refreshBatches(); } catch (exception) { error.value = exception.message; } });
</script>

<template>
  <div class="min-h-screen bg-slate-100 text-slate-900">
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white">
      <div class="mx-auto flex h-16 max-w-7xl items-center gap-9 px-5">
        <div class="whitespace-nowrap text-lg font-extrabold">OGAMI <span class="text-blue-600">LABEL STUDIO</span></div>
        <nav class="flex h-full gap-2">
          <button v-for="page in ['upload','history','record']" :key="page" @click="activePage=page" class="border-b-2 px-4 capitalize" :class="activePage===page?'border-blue-600 font-bold text-blue-700':'border-transparent text-slate-500'">{{ page === 'record' ? 'Records' : page }}</button>
        </nav>
        <span class="ml-auto text-xs text-slate-500">Public workspace</span>
      </div>
    </header>
    <main class="mx-auto max-w-7xl px-5 py-8">
      <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div><h1 class="text-3xl font-bold">{{ {upload:'Upload Excel',history:'Upload history',record:'Label records'}[activePage] }}</h1><p class="mt-1 text-sm text-slate-500">Each workbook has its own folder, with one label record for every row.</p></div>
        <input v-model="query" class="w-80 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search Excel, serial, part no…" />
      </div>
      <div v-if="error" role="alert" class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        <b class="block">Upload needs a correction</b>
        <p class="mt-1">{{ errorSummary }}</p>
        <details v-if="errorLines.length > 1" class="mt-2">
          <summary class="w-fit cursor-pointer font-medium text-red-900 underline underline-offset-2">Show {{ errorLines.length }} affected rows</summary>
          <ul class="mt-2 max-h-36 space-y-1 overflow-y-auto rounded-lg border border-red-200/80 bg-white/70 p-3 text-xs sm:text-sm">
            <li v-for="(line, index) in errorLines" :key="index">{{ line }}</li>
          </ul>
        </details>
        <p class="mt-2 text-red-700">No records were saved. Your selected file is still here; correct the workbook and upload it again.</p>
      </div>
      <div v-if="success" role="status" class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
        <div><b class="block">Upload complete and saved</b><span>{{ success.filename }} · {{ success.count }} row labels · folder “{{ success.name }}”</span></div>
        <button @click="activePage='history'; openBatch(selectedBatch.id)" class="rounded-lg border border-emerald-300 bg-white px-3 py-2 font-semibold text-emerald-800">View in history</button>
      </div>

      <section v-if="activePage==='upload'" class="grid gap-5 lg:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="text-lg font-bold">Upload one Excel file</h2><p class="mb-5 mt-1 text-sm text-slate-500">One file per submission · .xlsx, .xlsm, .xls, or .csv · up to 4 MB.</p>
          <div class="flex min-h-[88px] items-center gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" class="h-6 w-6" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3.75h6l5 5v11.5H7a2 2 0 0 1-2-2v-12.5a2 2 0 0 1 2-2Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13 3.75v5h5M8.5 14h7M8.5 17h7"/></svg>
            </div>
            <div class="min-w-0 flex-1">
              <strong class="block truncate text-sm">{{ file?.name ?? 'No file selected' }}</strong>
              <span class="mt-1 block text-xs text-slate-500">{{ file ? `${(file.size / 1024 / 1024).toFixed(2)} MB · 1 file` : 'Choose one Excel file · the first worksheet will be read' }}</span>
            </div>
            <label class="shrink-0 cursor-pointer rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-400 hover:text-blue-700 focus-within:ring-2 focus-within:ring-blue-500">
              {{ file ? 'Change file' : 'Browse files' }}
              <input ref="fileInput" class="sr-only" type="file" accept=".xlsx,.xlsm,.xls,.csv" @click="fileInput.value = ''" @change="onFileChange" />
            </label>
          </div>
          <label class="mt-4 block text-sm font-medium">Workbook / folder name<input v-model="batchName" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Excel 1" /></label>
          <button :disabled="!file||loading" @click="upload" class="mt-4 flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
            <span v-if="loading" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
            {{ loading?'Uploading and generating labels…':'Upload and generate labels' }}
          </button>
          <p v-if="loading" role="status" class="mt-3 text-sm text-blue-700">Uploading and validating your file. Keep this page open; confirmation will appear once it has been saved.</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold">Accurate codes</h2><p class="mt-3 text-sm leading-6 text-slate-600">QR, serial, quantity, part number, and supplier codes are generated from the matching Excel columns. Values are stored as text and are not rebuilt from other fields. Rows with missing required values or duplicate serial numbers are rejected before saving.</p><p class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">Workbook rows and the original file are stored server-side. Label records are saved in the configured database.</p></article>
      </section>

      <section v-if="activePage==='history' || activePage==='record'" class="grid gap-5 lg:grid-cols-[390px_1fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div v-if="!visibleBatches.length" class="p-8 text-center text-sm text-slate-500">No matching uploads yet.</div>
          <div v-for="batch in visibleBatches" :key="batch.id" class="mb-3 overflow-hidden rounded-xl border border-slate-200">
            <button @click="openBatch(batch.id)" class="flex w-full items-center justify-between bg-slate-50 p-4 text-left"><span><b>📁 {{ batch.name }}</b><small class="mt-1 block text-slate-500">{{ batch.row_count }} rows · {{ batch.original_filename }}</small></span><span>›</span></button>
            <div v-if="selectedBatch?.id===batch.id" class="max-h-[60vh] overflow-auto p-2">
              <button v-for="record in records" :key="record.id" @click="selectRecord(record)" class="flex w-full items-center justify-between border-b border-slate-100 px-3 py-3 text-left hover:bg-blue-50" :class="selectedRecord?.id===record.id?'bg-blue-50':''"><span><b>Row {{ record.row_number }}</b><small class="mt-1 block text-slate-500">{{ record.serial_no }} · {{ record.part_number }}</small></span><span class="text-xs text-blue-600">Open label ›</span></button>
            </div>
          </div>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <template v-if="selectedRecord">
            <div class="mb-4 flex items-center justify-between"><span class="text-sm text-slate-500">{{ selectedBatch?.name }} / Row {{ selectedRecord.row_number }} · {{ recordIndex+1 }} of {{ records.length }}</span><div class="flex gap-2"><button @click="moveRecord(-1)" :disabled="recordIndex<=0" class="rounded-lg bg-slate-100 px-3 py-2 disabled:opacity-40">‹ Previous</button><button @click="moveRecord(1)" :disabled="recordIndex>=records.length-1" class="rounded-lg bg-slate-100 px-3 py-2 disabled:opacity-40">Next ›</button><a :href="labelUrl(selectedRecord)" download="ogami-label.svg" class="rounded-lg bg-blue-600 px-3 py-2 text-white">Download SVG</a></div></div>
            <img :src="labelUrl(selectedRecord)" class="mx-auto block w-full max-w-[900px]" alt="Accurately encoded Ogami product label" />
            <div class="mt-4 grid gap-2 rounded-lg bg-slate-50 p-4 text-xs sm:grid-cols-2"><div>QR CODE: <code>{{ selectedRecord.qr_code }}</code></div><div>SERIAL CODE: <code>{{ selectedRecord.serial_code }}</code></div><div>QUANTITY CODE: <code>{{ selectedRecord.quantity_code }}</code></div><div>PART NUMBER CODE: <code>{{ selectedRecord.part_number_code }}</code></div><div>SUPPLIER CODE: <code>{{ selectedRecord.supplier_code }}</code></div></div>
          </template>
          <div v-else class="p-14 text-center text-slate-500">Select a row to view its label.</div>
        </article>
      </section>
    </main>
  </div>
</template>

