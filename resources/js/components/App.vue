<script setup>
import { computed, onMounted, ref, watch } from 'vue';

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
const labelImage = ref(null);
const downloading = ref(false);
const downloadError = ref('');
const deleteEnabled = ref(false);
const userName = document.querySelector('meta[name="user-name"]')?.content ?? '';
const deletingId = ref(null);
const historyMessage = ref('');
const historyError = ref('');
const analysis = ref(null);
const analyzing = ref(false);
const selectedTableId = ref('');
const codeTypes = ref({});
const editorOpen = ref(false);
const templateElements = ref([]);
const draggingElement = ref(null);
const canvasElement = ref(null);
const templateSaving = ref(false);
const templateMessage = ref('');
const templateError = ref('');
const builtInFields = [
    ['partNumber', 'Part number'], ['partName', 'Part name'], ['quantity', 'Quantity'], ['modelName', 'Model name'],
    ['cartonWeight', 'Carton weight'], ['supplierNumber', 'Supplier number'], ['mfgDate', 'Manufacturing date'],
    ['serialNo', 'Serial number'], ['supplierPn', 'Supplier part number'], ['qrCode', 'QR code'],
    ['serialCode', 'Serial code'], ['quantityCode', 'Quantity code'], ['partNumberCode', 'Part number code'], ['supplierCode', 'Supplier code'],
];
const selectedTable = computed(() => analysis.value?.tables?.find((table) => table.id === selectedTableId.value) ?? null);
const codeColumns = computed(() => selectedTable.value?.codeColumns ?? []);
watch(selectedTable, (table) => {
    codeTypes.value = Object.fromEntries((table?.codeColumns ?? []).map((column) => [column.header, column.suggestedType ?? '']));
});
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
const availableFields = computed(() => {
    const custom = selectedRecord.value?.data?.customFields ?? [];
    return [...builtInFields, ...custom.map((field) => [field.name, field.name])];
});
const elementValue = (element) => {
    const data = selectedRecord.value?.data ?? {};
    if (element.type === 'text' && !element.field) return element.label ?? '';
    return data[element.field] ?? data.customFields?.find((field) => field.name === element.field)?.value ?? '';
};
const codeTypeFor = (field) => {
    const chosen = selectedBatch.value?.field_config?.[field];
    if (chosen === 'qr' || chosen === 'barcode') return chosen;
    return /qr/i.test(field) ? 'qr' : 'barcode';
};

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
    if (!file.value || !selectedTable.value) return;
    loading.value = true; error.value = ''; success.value = null;
    const form = new FormData(); form.append('file', file.value); form.append('name', batchName.value || file.value.name);
    for (const [key, value] of Object.entries(selectedTable.value)) {
        if (['sheetIndex', 'headerRow', 'startColumn', 'endColumn', 'endRow'].includes(key)) form.append(`table[${key}]`, value);
    }
    form.append('code_types', JSON.stringify(codeTypes.value));
    try {
        const saved = await request('/api/batches', { method: 'POST', body: form });
        await refreshBatches();
        selectedBatch.value = saved;
        selectedRecord.value = saved.records?.[0] ?? null;
        success.value = { name: saved.name, filename: saved.original_filename, count: saved.row_count };
        file.value = null;
        batchName.value = '';
        analysis.value = null; selectedTableId.value = ''; codeTypes.value = {};
        if (fileInput.value) fileInput.value.value = '';
        activePage.value = 'history';
    } catch (exception) { error.value = exception.message; }
    finally { loading.value = false; }
}
async function analyzeWorkbook() {
    if (!file.value || analyzing.value) return;
    analyzing.value = true; error.value = ''; analysis.value = null; selectedTableId.value = ''; codeTypes.value = {};
    const form = new FormData(); form.append('file', file.value);
    try {
        analysis.value = await request('/api/analyze', { method: 'POST', body: form });
        selectedTableId.value = analysis.value.tables?.[0]?.id ?? '';
    } catch (exception) { error.value = exception.message; }
    finally { analyzing.value = false; }
}
function onFileChange(event) {
    error.value = '';
    success.value = null;
    analysis.value = null; selectedTableId.value = ''; codeTypes.value = {};
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
    analysis.value = null; selectedTableId.value = ''; codeTypes.value = {};
    if (input) input.value = '';
}
async function openBatch(id) {
    try { selectedBatch.value = await request(`/api/batches/${id}`); }
    catch (exception) { error.value = exception.message; }
}
function selectRecord(record) { selectedRecord.value = record; }
function makeDefaultTemplate() {
    const defs = [
        ['text','partNumber','PART NO.',12,12,190,24,15], ['text','partNumber','',205,22,340,45,40], ['qr','qrCode','',620,10,125,125,18],
        ['text','quantity','QUANTITY',12,158,160,24,17], ['text','quantity','',35,192,220,47,34],
        ['text','partName','PARTS DESCRIPTION',275,158,240,24,16], ['text','partName','',285,188,240,44,20],
        ['text','modelName','MODEL NAME',545,158,200,24,16], ['text','modelName','',550,188,190,44,20],
        ['text','cartonWeight','CARTON WEIGHT (Kg)',275,230,240,24,15], ['text','cartonWeight','',300,258,210,34,20],
        ['text','supplierNumber','SUPPLIER NUMBER',12,298,235,24,15], ['text','supplierNumber','',35,332,220,35,20],
        ['text','mfgDate','MFG DATE',275,298,240,24,15], ['text','mfgDate','',300,332,210,35,20],
        ['text','supplierPn','SUPPLIER PN',545,298,200,24,15], ['text','supplierPn','',550,332,190,35,20],
        ['text','serialNo','SERIAL NO.',12,410,240,24,16], ['text','serialNo','',80,435,360,38,20], ['text','serialCode','',40,478,400,44,17],
    ];
    const elements = defs.map(([type,field,label,x,y,w,h,fontSize],index) => ({ id:`el-${Date.now()}-${index}`, type, field, label, x,y,w,h,fontSize }));
    const configured = selectedRecord.value?.data?.customFields ?? [];
    for (const item of configured) {
        const type = selectedBatch.value?.field_config?.[item.name];
        if (!['qr','barcode'].includes(type)) continue;
        const index = elements.length;
        elements.push({ id:`el-${Date.now()}-${index}`, type, field:item.name, label:'', x:20 + (index % 3) * 235, y:15 + Math.floor(index / 3) * 115, w:type === 'qr' ? 110 : 210, h:type === 'qr' ? 110 : 54, fontSize:18 });
    }
    return elements;
}
function openEditor() {
    templateElements.value = structuredClone(selectedBatch.value?.label_template?.elements ?? makeDefaultTemplate());
    templateMessage.value = ''; templateError.value = ''; editorOpen.value = true;
}
function addCanvasElement(type, field = '') {
    const label = type === 'text' && !field ? 'Text' : '';
    templateElements.value.push({ id:`el-${Date.now()}-${Math.random().toString(16).slice(2)}`, type, field, label, x:30, y:30, w:type === 'qr' ? 120 : 220, h:type === 'qr' ? 120 : 44, fontSize:20 });
}
function canvasDrop(event) {
    if (!canvasElement.value) return;
    const rect = canvasElement.value.getBoundingClientRect();
    const x = Math.max(0, Math.min(760, (event.clientX - rect.left) * 760 / rect.width));
    const y = Math.max(0, Math.min(535, (event.clientY - rect.top) * 535 / rect.height));
    if (draggingElement.value) { draggingElement.value.x = x; draggingElement.value.y = y; draggingElement.value = null; return; }
    const type = event.dataTransfer.getData('application/ogami-type') || 'text';
    const field = event.dataTransfer.getData('application/ogami-field') || '';
    addCanvasElement(type, field);
    const added = templateElements.value.at(-1); added.x = x; added.y = y;
}
async function saveTemplate() {
    if (!selectedBatch.value) return;
    templateSaving.value = true; templateError.value = ''; templateMessage.value = '';
    try {
        const updated = await request(`/api/batches/${selectedBatch.value.id}/template`, { method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ template:{ elements:templateElements.value } }) });
        selectedBatch.value = updated;
        selectedRecord.value = updated.records?.find((row) => row.id === selectedRecord.value?.id) ?? updated.records?.[0] ?? null;
        templateMessage.value = 'Design saved for this workbook.';
    } catch (exception) { templateError.value = exception.message; }
    finally { templateSaving.value = false; }
}
async function deleteBatch(batch) {
    if (!deleteEnabled.value || deletingId.value || !window.confirm(`Delete “${batch.name}” and all ${batch.row_count} label records? This cannot be undone.`)) return;
    deletingId.value = `batch-${batch.id}`;
    historyError.value = '';
    historyMessage.value = '';
    try {
        await request(`/api/batches/${batch.id}`, { method: 'DELETE' });
        if (selectedBatch.value?.id === batch.id) {
            selectedBatch.value = null;
            selectedRecord.value = null;
        }
        await refreshBatches();
        historyMessage.value = `Workbook folder “${batch.name}” and its labels were deleted.`;
    } catch (exception) { historyError.value = exception.message; }
    finally { deletingId.value = null; }
}
async function deleteRecord(record) {
    if (!deleteEnabled.value || deletingId.value || !window.confirm(`Delete the label for Row ${record.row_number}? This cannot be undone.`)) return;
    const batchId = selectedBatch.value?.id;
    const previousIndex = recordIndex.value;
    const deletingSelectedRecord = selectedRecord.value?.id === record.id;
    deletingId.value = `record-${record.id}`;
    historyError.value = '';
    historyMessage.value = '';
    try {
        await request(`/api/records/${record.id}`, { method: 'DELETE' });
        if (batchId) {
            const updatedBatch = await request(`/api/batches/${batchId}`);
            selectedBatch.value = updatedBatch;
            if (deletingSelectedRecord) {
                selectedRecord.value = updatedBatch.records[Math.min(previousIndex, updatedBatch.records.length - 1)] ?? null;
            } else {
                selectedRecord.value = updatedBatch.records.find((item) => item.id === selectedRecord.value?.id) ?? null;
            }
        }
        await refreshBatches();
        historyMessage.value = `Row ${record.row_number} label was deleted.`;
    } catch (exception) { historyError.value = exception.message; }
    finally { deletingId.value = null; }
}
function logout() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/logout';
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    form.appendChild(token);
    document.body.appendChild(form);
    form.submit();
}
function moveRecord(step) {
    const next = records.value[recordIndex.value + step]; if (next) selectedRecord.value = next;
}
function labelUrl(record) { return `/api/records/${record.id}/label.svg?v=${encodeURIComponent(selectedBatch.value?.updated_at ?? record.updated_at ?? '')}`; }
function printLabel() {
    if (!selectedRecord.value) return;
    window.print();
}
async function downloadLabel(record) {
    const image = labelImage.value;
    if (!image || downloading.value) return;
    downloading.value = true;
    downloadError.value = '';
    try {
        if (typeof image.decode === 'function') await image.decode();
        else if (!image.complete) await new Promise((resolve, reject) => { image.onload = resolve; image.onerror = reject; });

        const canvas = document.createElement('canvas');
        canvas.width = 1800;
        canvas.height = Math.round(canvas.width * 535 / 760);
        const context = canvas.getContext('2d');
        if (!context) throw new Error('PNG conversion is not available in this browser.');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        const png = await new Promise((resolve, reject) => canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('The PNG image could not be created.')), 'image/png'));
        const safeName = (selectedBatch.value?.name || 'ogami-label').replace(/[<>:"/\\|?*\u0000-\u001F]/g, '-').trim() || 'ogami-label';
        const url = URL.createObjectURL(png);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${safeName}-row-${record.row_number}.png`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (exception) {
        downloadError.value = 'Could not create the PNG image. Please wait for the label to load and try again.';
    } finally {
        downloading.value = false;
    }
}

onMounted(async () => {
    try { await refreshBatches(); } catch (exception) { error.value = exception.message; }
    try { deleteEnabled.value = (await request('/api/delete-status')).enabled; } catch { deleteEnabled.value = false; }
});
</script>

<template>
  <div class="min-h-screen bg-slate-100 text-slate-900" @dragover.prevent @drop.prevent="editorOpen ? canvasDrop($event) : null">
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white">
      <div class="mx-auto flex h-16 max-w-7xl items-center gap-9 px-5">
        <div class="whitespace-nowrap text-lg font-extrabold">OGAMI <span class="text-blue-600">LABEL STUDIO</span></div>
        <nav class="flex h-full gap-2">
          <button v-for="page in ['upload','history']" :key="page" @click="activePage=page" class="border-b-2 px-4 capitalize" :class="activePage===page?'border-blue-600 font-bold text-blue-700':'border-transparent text-slate-500'">{{ page }}</button>
        </nav>
        <div class="ml-auto flex items-center gap-3 text-xs text-slate-500"><span>{{ userName }}</span><button @click="logout" class="rounded-lg border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50">Sign out</button></div>
      </div>
    </header>
    <main class="mx-auto max-w-7xl px-5 py-8">
      <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div><h1 class="text-3xl font-bold">{{ {upload:'Upload Excel',history:'Upload history'}[activePage] }}</h1><p class="mt-1 text-sm text-slate-500">Each workbook has its own folder, with one label record for every row.</p></div>
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
              <span class="mt-1 block text-xs text-slate-500">{{ file ? `${(file.size / 1024 / 1024).toFixed(2)} MB · 1 file` : 'Choose one Excel file · worksheets and table sections will be analyzed' }}</span>
            </div>
            <label class="shrink-0 cursor-pointer rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:border-blue-400 hover:text-blue-700 focus-within:ring-2 focus-within:ring-blue-500">
              {{ file ? 'Change file' : 'Browse files' }}
              <input ref="fileInput" class="sr-only" type="file" accept=".xlsx,.xlsm,.xls,.csv" @click="fileInput.value = ''" @change="onFileChange" />
            </label>
          </div>
          <button v-if="file && !analysis" :disabled="analyzing" @click="analyzeWorkbook" class="mt-4 rounded-lg border border-blue-300 bg-blue-50 px-4 py-2.5 font-semibold text-blue-800 disabled:opacity-50">{{ analyzing ? 'Analyzing workbook…' : 'Analyze workbook' }}</button>
          <div v-if="analysis" class="mt-4 space-y-4 rounded-xl border border-blue-100 bg-blue-50/60 p-4">
            <div><h3 class="font-bold">Workbook analysis</h3><p class="mt-1 text-sm text-slate-600">Found {{ analysis.sheets.length }} worksheet(s) and {{ analysis.tables.length }} table section(s). Select the table to use.</p></div>
            <p v-if="!analysis.tables.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">No table with a header and data rows was detected. Check that the table has column names in its first row.</p>
            <label v-else class="block text-sm font-medium">Detected table<select v-model="selectedTableId" class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2"><option v-for="table in analysis.tables" :key="table.id" :value="table.id">{{ table.sheetName }} · rows {{ table.headerRow }}–{{ table.endRow }} · {{ table.headers.join(', ') }}</option></select></label>
            <div v-if="selectedTable" class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
              <table class="min-w-full text-left text-xs"><thead class="bg-slate-100"><tr><th v-for="(header,index) in selectedTable.headers" :key="index" class="whitespace-nowrap px-3 py-2 font-semibold">{{ header }}</th></tr></thead><tbody><tr v-for="(row,index) in selectedTable.sample" :key="index"><td v-for="(value,col) in row" :key="col" class="max-w-48 truncate border-t border-slate-100 px-3 py-2">{{ value }}</td></tr></tbody></table>
            </div>
            <div v-if="selectedTable && codeColumns.length" class="space-y-3 rounded-lg bg-white p-3">
              <div><b class="text-sm">Choose how code columns should appear</b><p class="mt-1 text-xs text-slate-500">These settings are saved with this upload. Original cell values stay unchanged.</p></div>
              <label v-for="column in codeColumns" :key="column.header" class="flex flex-wrap items-center justify-between gap-2 text-sm"><span>{{ column.header }} <small v-if="column.suggestedType" class="text-slate-500">· suggested {{ column.suggestedType === 'qr' ? 'QR code' : 'barcode' }}</small></span><select v-model="codeTypes[column.header]" class="rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="">Choose output…</option><option value="barcode">Barcode</option><option value="qr">QR code</option><option value="text">Text only</option><option value="ignore">Do not show</option></select></label>
            </div>
            <p v-if="selectedTable" class="text-xs text-slate-500">Other columns are kept as custom fields for the label design editor.</p>
          </div>
          <label class="mt-4 block text-sm font-medium">Workbook / folder name<input v-model="batchName" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Excel 1" /></label>
          <button :disabled="!file||!selectedTable||codeColumns.some((column)=>!codeTypes[column.header])||loading" @click="upload" class="mt-4 flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
            <span v-if="loading" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
            {{ loading?'Uploading and generating labels…':'Upload and generate labels' }}
          </button>
          <p v-if="loading" role="status" class="mt-3 text-sm text-blue-700">Uploading and validating your file. Keep this page open; confirmation will appear once it has been saved.</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold">Flexible import, accurate labels</h2><p class="mt-3 text-sm leading-6 text-slate-600">Analyze every worksheet and detected table section before importing. Known columns are matched automatically; unfamiliar columns are preserved as custom fields. Code-like columns can be configured as QR, barcode, text, or hidden. Every non-empty row generates a label, even if some values are blank. Original cell values are read directly and never guessed.</p><p class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">Your table selection and code display choices are saved with the workbook for the label design editor.</p></article>
      </section>

      <section v-if="activePage==='history'" class="space-y-4">
        <div v-if="!deleteEnabled" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Sign in again to manage your workbook history.</div>
        <div v-if="historyMessage" role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ historyMessage }}</div>
        <div v-if="historyError" role="alert" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ historyError }}</div>
        <div class="grid gap-5 lg:grid-cols-[390px_1fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div v-if="!visibleBatches.length" class="p-8 text-center text-sm text-slate-500">No matching uploads yet.</div>
          <div v-for="batch in visibleBatches" :key="batch.id" class="mb-3 overflow-hidden rounded-xl border border-slate-200">
            <div class="flex items-center gap-2 bg-slate-50 p-2">
              <button @click="openBatch(batch.id)" class="flex min-w-0 flex-1 items-center justify-between p-2 text-left"><span class="min-w-0"><b class="block truncate">📁 {{ batch.name }}</b><small class="mt-1 block truncate text-slate-500">{{ batch.row_count }} rows · {{ batch.original_filename }}</small></span><span>›</span></button>
              <button @click="deleteBatch(batch)" :disabled="!deleteEnabled||deletingId!==null" class="shrink-0 rounded-lg px-2 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40" :title="deleteEnabled ? 'Delete this workbook folder and all its labels' : 'Sign in again to delete your data'">{{ deletingId===`batch-${batch.id}` ? 'Deleting…' : 'Delete folder' }}</button>
            </div>
            <div v-if="selectedBatch?.id===batch.id" class="max-h-[60vh] overflow-auto p-2">
              <div v-for="record in records" :key="record.id" class="flex items-center gap-1 border-b border-slate-100 hover:bg-blue-50" :class="selectedRecord?.id===record.id?'bg-blue-50':''">
                <button @click="selectRecord(record)" class="flex min-w-0 flex-1 items-center justify-between px-3 py-3 text-left"><span class="min-w-0"><b>Row {{ record.row_number }}</b><small class="mt-1 block truncate text-slate-500">{{ record.serial_no }} · {{ record.part_number }}</small></span><span class="text-xs text-blue-600">Open label ›</span></button>
                <button @click="deleteRecord(record)" :disabled="!deleteEnabled||deletingId!==null" class="shrink-0 rounded-lg px-2 py-2 text-xs font-medium text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40" :title="deleteEnabled ? 'Delete this label record' : 'Sign in again to delete your data'">{{ deletingId===`record-${record.id}` ? 'Deleting…' : 'Delete' }}</button>
              </div>
            </div>
          </div>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <template v-if="selectedRecord">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><span class="text-sm text-slate-500">{{ selectedBatch?.name }} / Row {{ selectedRecord.row_number }} · {{ recordIndex+1 }} of {{ records.length }}</span><div class="flex flex-wrap gap-2"><button @click="moveRecord(-1)" :disabled="recordIndex<=0||downloading" class="rounded-lg bg-slate-100 px-3 py-2 disabled:opacity-40">‹ Previous</button><button @click="moveRecord(1)" :disabled="recordIndex>=records.length-1||downloading" class="rounded-lg bg-slate-100 px-3 py-2 disabled:opacity-40">Next ›</button><button @click="openEditor" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 font-medium text-indigo-800 hover:bg-indigo-100">Edit design</button><button @click="printLabel" class="rounded-lg border border-slate-300 bg-white px-3 py-2 font-medium text-slate-700 hover:bg-slate-50">Print label</button><button @click="downloadLabel(selectedRecord)" :disabled="downloading" class="rounded-lg bg-blue-600 px-3 py-2 text-white disabled:opacity-50">{{ downloading ? 'Preparing PNG…' : 'Download PNG' }}</button></div></div>
            <p class="mb-2 text-xs text-slate-500">Label preview · Row {{ selectedRecord.row_number }} · Serial no. {{ selectedRecord.serial_no }}</p>
            <img ref="labelImage" :key="selectedRecord.id" :src="labelUrl(selectedRecord)" class="mx-auto block w-full max-w-[900px]" alt="Accurately encoded Ogami product label" />
            <div class="print-label" aria-hidden="true"><img :src="labelUrl(selectedRecord)" alt="" /></div>
            <p v-if="downloadError" role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ downloadError }}</p>
            <div class="mt-4 grid gap-2 rounded-lg bg-slate-50 p-4 text-xs sm:grid-cols-2"><div>QR CODE: <code>{{ selectedRecord.qr_code }}</code></div><div>SERIAL CODE: <code>{{ selectedRecord.serial_code }}</code></div><div>QUANTITY CODE: <code>{{ selectedRecord.quantity_code }}</code></div><div>PART NUMBER CODE: <code>{{ selectedRecord.part_number_code }}</code></div><div>SUPPLIER CODE: <code>{{ selectedRecord.supplier_code }}</code></div></div>
          </template>
          <div v-else class="p-14 text-center text-slate-500">Select a row to view its label.</div>
        </article>
        </div>
      </section>
    </main>
    <div v-if="editorOpen" class="fixed inset-0 z-40 flex flex-col bg-slate-100" role="dialog" aria-modal="true" aria-label="Label design editor">
      <header class="flex flex-wrap items-center gap-3 border-b border-slate-200 bg-white px-5 py-3"><div class="mr-auto"><b class="text-lg">Label design editor</b><p class="text-xs text-slate-500">Drag a field onto the canvas. Select an item to change its field, size, or remove it.</p></div><span v-if="templateMessage" class="text-sm text-emerald-700">{{ templateMessage }}</span><span v-if="templateError" class="text-sm text-red-700">{{ templateError }}</span><button @click="saveTemplate" :disabled="templateSaving" class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white disabled:opacity-50">{{ templateSaving ? 'Saving…' : 'Save design' }}</button><button @click="editorOpen=false" class="rounded-lg border border-slate-300 px-4 py-2">Close</button></header>
      <div class="grid min-h-0 flex-1 lg:grid-cols-[270px_minmax(0,1fr)_300px]">
        <aside class="overflow-auto border-r border-slate-200 bg-white p-4"><h3 class="mb-3 font-bold">Text fields</h3><div class="space-y-2"><button v-for="[field,label] in availableFields" :key="field" draggable="true" @dragstart="(event)=>{event.dataTransfer.setData('application/ogami-type','text');event.dataTransfer.setData('application/ogami-field',field)}" @click="addCanvasElement('text',field)" class="block w-full cursor-grab rounded-lg border border-slate-200 p-2 text-left text-sm hover:border-blue-400">{{ label }} <small class="block truncate text-slate-400">{{ field }}</small></button></div><h3 class="mb-2 mt-6 font-bold">Code fields</h3><div class="space-y-2"><template v-for="[field,label] in availableFields.filter(([field])=>/code|qr|barcode/i.test(field))" :key="field"><div class="rounded-lg border border-slate-200 p-2"><b class="block text-xs">{{ label }}</b><button draggable="true" @dragstart="(event)=>{event.dataTransfer.setData('application/ogami-type',codeTypeFor(field));event.dataTransfer.setData('application/ogami-field',field)}" @click="addCanvasElement(codeTypeFor(field),field)" class="mt-1 w-full rounded bg-slate-50 px-2 py-1 text-left text-xs hover:bg-blue-50">{{ codeTypeFor(field)==='qr' ? '▦ Add QR code' : '▥ Add barcode' }} <span v-if="selectedBatch?.field_config?.[field]" class="text-slate-500">· selected</span></button><div class="mt-1 flex gap-1"><button @click="addCanvasElement(codeTypeFor(field)==='qr'?'barcode':'qr',field)" class="text-[11px] text-blue-700 underline">Add as {{ codeTypeFor(field)==='qr'?'barcode':'QR code' }} instead</button></div></div></template></div><button @click="addCanvasElement('text')" class="mt-5 w-full rounded-lg bg-slate-100 p-2 text-sm font-semibold">+ Add static text</button></aside>
        <section class="flex min-w-0 items-center justify-center overflow-auto p-5 lg:p-10" @dragover.prevent @drop.prevent="canvasDrop($event)"><div ref="canvasElement" class="relative aspect-[760/535] w-full max-w-[1100px] shrink-0 overflow-hidden bg-white shadow-xl ring-1 ring-slate-300" @dragover.prevent @drop.prevent.stop="canvasDrop($event)"><div class="pointer-events-none absolute inset-0 border-2 border-black"></div><div v-for="element in templateElements" :key="element.id" draggable="true" @dragstart="(event)=>{draggingElement=element;event.dataTransfer.setData('text/plain',element.id)}" class="absolute cursor-move overflow-hidden border border-dashed border-blue-300/80 bg-white/30 px-1 text-slate-900 hover:border-blue-600" :style="{left:`${element.x/760*100}%`,top:`${element.y/535*100}%`,width:`${element.w/760*100}%`,height:`${element.h/535*100}%`,fontSize:`${element.fontSize||20}px`}"><span v-if="element.type==='qr'" class="grid h-full place-items-center bg-slate-100 text-4xl">▦</span><span v-else-if="element.type==='barcode'" class="grid h-full place-items-center bg-white text-3xl tracking-widest">▥ ▥ ▥</span><span v-else class="block truncate">{{ element.label || elementValue(element) || (element.field ? `[${element.field}]` : 'Text') }}</span></div></div></section>
        <aside class="overflow-auto border-l border-slate-200 bg-white p-4"><h3 class="font-bold">Selected element</h3><p class="mt-1 text-xs text-slate-500">Choose an item on the canvas list below to edit its position and content.</p><div class="mt-4 space-y-3"><div v-for="element in templateElements" :key="element.id" class="rounded-xl border border-slate-200 p-3"><div class="mb-2 flex items-center justify-between"><b class="text-sm">{{ element.type==='text' ? 'Text' : element.type==='qr' ? 'QR code' : 'Barcode' }}</b><button @click="templateElements=templateElements.filter(item=>item.id!==element.id)" class="text-xs text-red-600">Remove</button></div><select v-if="element.type!=='text' || element.field" v-model="element.field" class="mb-2 w-full rounded border border-slate-300 px-2 py-1 text-xs"><option v-for="[field,label] in availableFields" :key="field" :value="field">{{ label }}</option></select><input v-if="element.type==='text'" v-model="element.label" placeholder="Optional label" class="mb-2 w-full rounded border border-slate-300 px-2 py-1 text-xs"><div class="grid grid-cols-2 gap-2"><label v-for="key in ['x','y','w','h']" :key="key" class="text-xs capitalize text-slate-500">{{ key }}<input v-model.number="element[key]" type="number" min="0" max="760" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"></label></div><label v-if="element.type==='text'" class="mt-2 block text-xs text-slate-500">Font size<input v-model.number="element.fontSize" type="number" min="8" max="64" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"></label></div><p v-if="!templateElements.length" class="mt-4 text-sm text-slate-500">No elements yet. Drag fields from the left panel.</p></div></aside>
      </div>
    </div>
  </div>
</template>
