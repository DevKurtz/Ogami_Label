<script setup>
import { computed, onMounted, ref } from 'vue';

const batches = ref([]);
const selectedBatch = ref(null);
const selectedRecord = ref(null);
const file = ref(null);
const batchName = ref('');
const query = ref('');
const loading = ref(false);
const error = ref('');
const activePage = ref('upload');

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
    if (!response.ok) throw new Error(Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? 'May nangyaring error.');
    return body;
}
async function refreshBatches() {
    batches.value = await request('/api/batches');
}
async function upload() {
    if (!file.value) return;
    loading.value = true; error.value = '';
    const form = new FormData(); form.append('file', file.value); form.append('name', batchName.value || file.value.name);
    try {
        const saved = await request('/api/batches', { method: 'POST', body: form });
        await refreshBatches(); await openBatch(saved.id); activePage.value = 'record';
    } catch (exception) { error.value = exception.message; }
    finally { loading.value = false; }
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
          <button v-for="page in ['upload','history','record']" :key="page" @click="activePage=page" class="border-b-2 px-4 capitalize" :class="activePage===page?'border-blue-600 font-bold text-blue-700':'border-transparent text-slate-500'">{{ page }}</button>
        </nav>
        <span class="ml-auto text-xs text-slate-500">Public workspace</span>
      </div>
    </header>
    <main class="mx-auto max-w-7xl px-5 py-8">
      <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div><h1 class="text-3xl font-bold">{{ {upload:'Upload Excel',history:'Upload history',record:'Label records'}[activePage] }}</h1><p class="mt-1 text-sm text-slate-500">Bawat workbook ay may sariling folder at bawat row ay may label record.</p></div>
        <input v-model="query" class="w-80 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search Excel, serial, part no…" />
      </div>
      <div v-if="error" class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ error }}</div>

      <section v-if="activePage==='upload'" class="grid gap-5 lg:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="text-lg font-bold">Import workbook</h2><p class="mb-5 mt-1 text-sm text-slate-500">Tinatanggap ang .xlsx, .xlsm, .xls, at .csv (4 MB max).</p>
          <label class="flex min-h-44 cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-blue-300 bg-blue-50 p-6 text-center">
            <strong>Drop your Excel file here or browse</strong><span class="mt-2 text-sm text-slate-500">{{ file?.name ?? 'First worksheet, fixed Ogami headers · 4 MB max' }}</span>
            <input class="hidden" type="file" accept=".xlsx,.xlsm,.csv" @change="file=$event.target.files[0]" />
          </label>
          <label class="mt-4 block text-sm font-medium">Workbook / folder name<input v-model="batchName" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Excel 1" /></label>
          <button :disabled="!file||loading" @click="upload" class="mt-4 rounded-lg bg-blue-600 px-4 py-3 font-semibold text-white disabled:opacity-50">{{ loading?'Checking rows…':'Validate & generate records' }}</button>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold">Accurate codes</h2><p class="mt-3 text-sm leading-6 text-slate-600">QR, serial, quantity, part number, and supplier codes are generated from the matching Excel columns. Values are stored as text and are not rebuilt from other fields. Rows with missing required values or duplicate serial numbers are rejected before saving.</p><p class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">Workbook rows and the original file are stored server-side. Label records are saved in the configured database.</p></article>
      </section>

      <section v-if="activePage==='history' || activePage==='record'" class="grid gap-5 lg:grid-cols-[390px_1fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div v-if="!visibleBatches.length" class="p-8 text-center text-sm text-slate-500">Wala pang matching upload.</div>
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
          <div v-else class="p-14 text-center text-slate-500">Pumili ng row para makita ang label.</div>
        </article>
      </section>
    </main>
  </div>
</template>
