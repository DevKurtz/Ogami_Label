<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import QRCode from 'qrcode';

const toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 3800,
    timerProgressBar: true,
});
function notify(icon, title, text = '') {
    return toast.fire({ icon, title, text });
}
async function confirmDelete(title, text, confirmButtonText) {
    const result = await Swal.fire({
        title,
        text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626',
        reverseButtons: true,
        focusCancel: true,
    });
    return result.isConfirmed;
}

const batches = ref([]);
const selectedBatch = ref(null);
const selectedRecord = ref(null);
const loadingBatchId = ref(null);
const loadingRecordId = ref(null);
let recordDetailRequestId = 0;
const file = ref(null);
const fileInput = ref(null);
const batchName = ref('');
const query = ref('');
const loading = ref(false);
const error = ref('');
const success = ref(null);
const activePage = ref(window.location.pathname.toLowerCase().includes('history') || new URLSearchParams(window.location.search).get('page') === 'history' ? 'history' : 'upload');
const labelImage = ref(null);
const labelImageLoading = ref(false);
const labelImageFailed = ref(false);
const downloading = ref(false);
const pdfDownloading = ref(false);
const pdfProgress = ref({ current: 0, total: 0 });
const pdfBatchId = ref(null);
const pdfStage = ref('Loading labels');
const pdfBatchName = ref('');
const pdfProgressPercent = computed(() => pdfStage.value === 'Building PDF' ? 100 : pdfProgress.value.total ? Math.round(pdfProgress.value.current / pdfProgress.value.total * 100) : 0);
const downloadError = ref('');
const deleteEnabled = ref(false);
const userName = document.querySelector('meta[name="user-name"]')?.content ?? '';
let redirectingAfterAuthFailure = false;
const deletingId = ref(null);
const deletingFolderName = ref('');
const deletingFolder = computed(() => typeof deletingId.value === 'string' && deletingId.value.startsWith('batch-'));
const historyLoading = ref(true);
const historyMessage = ref('');
const historyError = ref('');
const analysis = ref(null);
const analyzing = ref(false);
const selectedTableIds = ref([]);
const codeTypes = ref({});
const editorOpen = ref(false);
const templateElements = ref([]);
const editingTextId = ref(null);
const canvasElement = ref(null);
const qrPreviews = ref({});
const templateSaving = ref(false);
const templateMessage = ref('');
const templateError = ref('');
const draftDesignMode = ref(false);
const draftTemplates = ref({});
const designTableIndex = ref(0);
const builtInFields = [
    ['partNumber', 'Part number'], ['partName', 'Part name'], ['quantity', 'Quantity'], ['modelName', 'Model name'],
    ['cartonWeight', 'Carton weight'], ['supplierNumber', 'Supplier number'], ['mfgDate', 'Manufacturing date'],
    ['serialNo', 'Serial number'], ['supplierPn', 'Supplier part number'], ['qrCode', 'QR code'],
    ['serialCode', 'Serial code'], ['quantityCode', 'Quantity code'], ['partNumberCode', 'Part number code'], ['supplierCode', 'Supplier code'],
];
const selectedTables = computed(() => (analysis.value?.tables ?? []).filter((table) => selectedTableIds.value.includes(table.id)));
const designTables = computed(() => selectedTables.value.filter((table) => !table.legacyFormat));
const designTable = computed(() => designTables.value[designTableIndex.value] ?? null);
function initialCodeTypes(tables) {
    return Object.fromEntries(tables.map((table) => [table.id, Object.fromEntries((table.codeColumns ?? []).map((column) => [column.header, column.suggestedType ?? '']))]));
}
function selectRecommendedTables() {
    const tables = analysis.value?.tables ?? [];
    selectedTableIds.value = tables.filter((table) => table.defaultSelected !== false).map((table) => table.id);
    codeTypes.value = initialCodeTypes(tables);
}
function selectAllTables() {
    selectedTableIds.value = (analysis.value?.tables ?? []).map((table) => table.id);
    codeTypes.value = initialCodeTypes(analysis.value?.tables ?? []);
}
function selectNoTables() { selectedTableIds.value = []; }
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
    if (draftDesignMode.value) return (designTable.value?.headers ?? []).map((field) => [field, field]);
    const custom = selectedRecord.value?.data?.customFields ?? [];
    return [...builtInFields, ...custom.map((field) => [field.name, field.name])];
});
const elementValue = (element) => {
    if (draftDesignMode.value) {
        const headers = designTable.value?.headers ?? [];
        const row = designTable.value?.sample?.[0] ?? [];
        const index = headers.indexOf(element.field);
        return index >= 0 ? String(row[index] ?? '') : '';
    }
    const data = selectedRecord.value?.data ?? {};
    if (element.type === 'text' && !element.field) return element.label ?? '';
    return data[element.field] ?? data.customFields?.find((field) => field.name === element.field)?.value ?? '';
};
let qrPreviewRequest = 0;
watch(
    () => templateElements.value.filter((element) => element.type === 'qr').map((element) => ({ id: element.id, value: String(elementValue(element) ?? '') })),
    async (codes) => {
        const requestId = ++qrPreviewRequest;
        const next = {};
        await Promise.all(codes.map(async ({ id, value }) => {
            if (!value.trim()) { next[id] = ''; return; }
            try { next[id] = await QRCode.toDataURL(value, { errorCorrectionLevel: 'M', margin: 2, width: 360, color: { dark: '#000000', light: '#ffffff' } }); }
            catch { next[id] = null; }
        }));
        if (requestId === qrPreviewRequest) qrPreviews.value = next;
    },
    { immediate: true },
);
async function request(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '', ...options.headers } });
    if (response.status === 401 && url.startsWith('/api/') && !redirectingAfterAuthFailure) {
        redirectingAfterAuthFailure = true;
        window.location.replace('/history');
        throw new Error('Your sign-in session expired. Sign in again to load History.');
    }
    const body = await response.json();
    if (!response.ok) throw new Error(Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? 'Something went wrong. Please try again.');
    return body;
}
async function refreshBatches() {
    batches.value = await request('/api/batches');
}
async function upload() {
    if (!file.value || !selectedTables.value.length) return;
    loading.value = true; error.value = ''; success.value = null;
    const form = new FormData(); form.append('file', file.value); form.append('name', batchName.value || file.value.name.replace(/\.[^.]+$/, ''));
    form.append('tables', JSON.stringify(selectedTables.value.map((table) => {
        const chosen = { ...table, codeTypes: codeTypes.value[table.id] ?? {} };
        if (!table.legacyFormat) chosen.labelTemplate = draftTemplates.value[table.id] ?? null;
        return chosen;
    })));
    try {
        const result = await request('/api/batches', { method: 'POST', body: form });
        await refreshBatches();
        const saved = result.batches?.[0];
        if (!saved) throw new Error('No workbook tables were saved. Analyze the file and choose at least one table.');
        selectedBatch.value = saved;
        selectedRecord.value = saved.records?.[0] ?? null;
        success.value = { name: batchName.value || file.value.name.replace(/\.[^.]+$/, ''), filename: saved.original_filename, count: result.batches.reduce((total, batch) => total + batch.row_count, 0), batchCount: result.batches.length };
        notify('success', 'Upload complete', `${success.value.count} label record(s) saved to History.`);
        file.value = null;
        batchName.value = '';
        analysis.value = null; selectedTableIds.value = []; codeTypes.value = {}; draftTemplates.value = {};
        if (fileInput.value) fileInput.value.value = '';
        activePage.value = 'history';
    } catch (exception) { error.value = exception.message; notify('error', 'Upload failed', exception.message); }
    finally { loading.value = false; }
}
async function analyzeWorkbook() {
    if (!file.value || analyzing.value) return;
    analyzing.value = true; error.value = ''; analysis.value = null; selectedTableIds.value = []; codeTypes.value = {};
    const form = new FormData(); form.append('file', file.value);
    try {
        analysis.value = await request('/api/analyze', { method: 'POST', body: form });
        selectRecommendedTables();
        draftTemplates.value = {};
        notify('success', 'Analysis complete', `${analysis.value.tables.length} table(s) found. Review the selection, then click Upload and generate labels.`);
    } catch (exception) { error.value = exception.message; notify('error', 'Analysis failed', exception.message); }
    finally { analyzing.value = false; }
}
function onFileChange(event) {
    error.value = '';
    success.value = null;
    analysis.value = null; selectedTableIds.value = []; codeTypes.value = {}; draftTemplates.value = {};
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
    analysis.value = null; selectedTableIds.value = []; codeTypes.value = {}; draftTemplates.value = {};
    if (input) input.value = '';
}
async function openBatch(id) {
    const summary = batches.value.find((batch) => batch.id === id);
    selectedBatch.value = { ...(summary ?? { id }), records: [] };
    selectedRecord.value = null;
    loadingBatchId.value = id;
    try {
        const batch = await request(`/api/batches/${id}`);
        if (selectedBatch.value?.id !== id) return;
        selectedBatch.value = batch;
        const firstRecord = batch.records?.[0] ?? null;
        if (firstRecord) selectRecord(firstRecord);
    }
    catch (exception) {
        if (selectedBatch.value?.id === id) { selectedBatch.value = null; selectedRecord.value = null; }
        error.value = exception.message; notify('error', 'Could not open folder', exception.message);
    }
    finally { if (loadingBatchId.value === id) loadingBatchId.value = null; }
}
function toggleBatch(id) {
    if (selectedBatch.value?.id === id) {
        selectedBatch.value = null; selectedRecord.value = null; loadingBatchId.value = null;
        loadingRecordId.value = null; recordDetailRequestId++;
        return;
    }
    openBatch(id);
}
function selectRecord(record) {
    selectedRecord.value = record;
    if (record.data) return;
    const requestId = ++recordDetailRequestId;
    loadingRecordId.value = record.id;
    request(`/api/records/${record.id}`)
        .then((details) => { if (requestId === recordDetailRequestId && selectedRecord.value?.id === record.id) selectedRecord.value = details; })
        .catch((exception) => { if (requestId === recordDetailRequestId) notify('error', 'Could not load row details', exception.message); })
        .finally(() => { if (requestId === recordDetailRequestId) loadingRecordId.value = null; });
}
function makeLegacyTemplate() {
    let index = 0;
    const elements = [];
    const add = (type, field, label, x, y, w, h, fontSize = 18, align = 'left') => elements.push({ id: `legacy-${++index}`, type, field, label, x, y, w, h, fontSize, align });
    const box = (x, y, w, h) => add('box', '', '', x, y, w, h);
    const text = (field, label, x, y, w, h, fontSize = 18, align = 'left') => add('text', field, label, x, y, w, h, fontSize, align);
    const barcode = (field, x, y, w, h) => add('barcode', field, '', x, y, w, h);

    [[0,0,760,143],[0,143,253,146],[253,143,293,77],[546,143,214,77],[253,220,293,69],[546,220,214,69],[0,289,253,116],[253,289,293,116],[253,350,293,55],[546,289,214,116],[0,405,437,130],[437,405,323,130]].forEach((cell) => box(...cell));
    text('', 'PART NO.', 8, 5, 150, 22, 17); text('', '(P)', 8, 25, 60, 22, 17); text('partNumber', '', 0, 20, 650, 52, 62, 'center');
    barcode('partNumberCode', 100, 78, 460, 45); text('partNumberCode', '', 100, 129, 460, 12, 10, 'center'); add('qr', 'qrCode', '', 600, 12, 110, 110);
    text('', 'QUANTITY', 8, 146, 180, 22, 17); text('', '(Q)', 8, 172, 60, 22, 17); text('quantity', '', 71, 185, 190, 49, 55);
    barcode('quantityCode', 23, 240, 224, 31); text('quantityCode', '', 40, 274, 190, 12, 10, 'center');
    text('', 'PARTS DESCRIPTION', 260, 146, 275, 22, 17); text('partName', '', 260, 180, 280, 35, 22, 'center');
    text('', 'MODEL NAME', 552, 146, 205, 22, 17); text('modelName', '', 552, 180, 205, 35, 22, 'center');
    text('', 'CARTON WEIGHT (Kg)', 260, 224, 275, 22, 17); text('cartonWeight', '', 260, 250, 280, 35, 25, 'center');
    text('', 'COMPONENT S/N', 552, 224, 205, 22, 17);
    text('', 'SUPPLIER NUMBER', 8, 293, 245, 22, 17); text('', '(V)', 8, 319, 60, 22, 17); text('supplierNumber', '', 85, 319, 230, 34, 22, 'center');
    barcode('supplierCode', 35, 354, 190, 36); text('supplierCode', '', 35, 391, 190, 12, 10, 'center');
    text('', 'MFG DAT', 260, 293, 275, 22, 17); text('mfgDate', '', 260, 315, 280, 38, 25, 'center');
    text('', 'SUPPLIER PN', 552, 293, 205, 22, 17); text('', '(2P)', 552, 327, 205, 22, 17);
    text('', 'SERIAL NO', 8, 410, 180, 22, 17); text('', '(S)', 8, 435, 45, 22, 17); text('serialNo', '', 15, 435, 405, 32, 22, 'center');
    barcode('serialCode', 38, 474, 361, 39); text('serialCode', '', 38, 518, 361, 12, 10, 'center');
    text('', 'MADE IN PHILIPPINES', 444, 410, 310, 22, 17); text('', 'PHILIPPINES OGAMI', 450, 460, 300, 30, 17); text('', 'CORPORATION', 450, 482, 300, 30, 17);
    return elements;
}
function beginInlineTextEdit(event, element) {
    if (element.field) return;
    editingTextId.value = element.id;
    nextTick(() => {
        const editor = canvasElement.value?.querySelector(`[data-text-widget="${element.id}"]`);
        if (!editor) return;
        editor.focus();
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(editor);
        selection?.removeAllRanges();
        selection?.addRange(range);
    });
}
function updateInlineText(event, element) { element.label = event.currentTarget.textContent ?? ''; }
function finishInlineTextEdit() { editingTextId.value = null; }
function openEditor() {
    draftDesignMode.value = false;
    const savedElements = selectedBatch.value?.label_template?.elements;
    templateElements.value = structuredClone(Array.isArray(savedElements) && savedElements.length ? savedElements : makeLegacyTemplate());
    const isLegacyLayout = templateElements.value.some((element) => String(element.id ?? '').startsWith('legacy-'));
    if (isLegacyLayout) {
        templateElements.value.forEach((element) => {
            if (element.type === 'text' && element.field === 'partNumber' && Number(element.y) < 143) {
                element.x = 0;
                element.w = 650;
                element.align = 'center';
            }
            if (element.type === 'barcode' && element.field === 'quantityCode' && Number(element.y) >= 200 && Number(element.y) < 289) {
                element.x = 23; element.y = 240; element.w = 224; element.h = 31;
            }
            if (element.type === 'text' && element.field === 'quantityCode' && Number(element.y) >= 250 && Number(element.y) < 300) {
                element.x = 40; element.y = 274; element.w = 190; element.h = 12; element.align = 'center';
            }
        });
    }
    editingTextId.value = null;
    templateMessage.value = ''; templateError.value = ''; editorOpen.value = true;
}
function startDesign() {
    draftDesignMode.value = true;
    designTableIndex.value = 0;
    templateElements.value = structuredClone(draftTemplates.value[designTables.value[0]?.id]?.elements ?? []);
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
    const type = event.dataTransfer.getData('application/ogami-type') || 'text';
    const field = event.dataTransfer.getData('application/ogami-field') || '';
    addCanvasElement(type, field);
    const added = templateElements.value.at(-1);
    added.x = Math.round(Math.min(760 - added.w, x));
    added.y = Math.round(Math.min(535 - added.h, y));
}
let activeCanvasInteraction = null;
function beginCanvasInteraction(event, element, mode) {
    if (event.button !== 0 || !canvasElement.value) return;
    event.preventDefault();
    const bounds = canvasElement.value.getBoundingClientRect();
    activeCanvasInteraction = {
        element,
        mode,
        startX: event.clientX,
        startY: event.clientY,
        elementX: Number(element.x),
        elementY: Number(element.y),
        elementW: Number(element.w),
        elementH: Number(element.h),
        scaleX: bounds.width / 760,
        scaleY: bounds.height / 535,
    };
    window.addEventListener('pointermove', moveCanvasInteraction);
    window.addEventListener('pointerup', endCanvasInteraction);
    window.addEventListener('pointercancel', endCanvasInteraction);
}
function moveCanvasInteraction(event) {
    const active = activeCanvasInteraction;
    if (!active) return;
    const dx = (event.clientX - active.startX) / active.scaleX;
    const dy = (event.clientY - active.startY) / active.scaleY;
    if (active.mode === 'resize') {
        active.element.w = Math.round(Math.max(20, Math.min(760 - active.elementX, active.elementW + dx)));
        active.element.h = Math.round(Math.max(20, Math.min(535 - active.elementY, active.elementH + dy)));
    } else {
        active.element.x = Math.round(Math.max(0, Math.min(760 - active.elementW, active.elementX + dx)));
        active.element.y = Math.round(Math.max(0, Math.min(535 - active.elementH, active.elementY + dy)));
    }
}
function endCanvasInteraction() {
    activeCanvasInteraction = null;
    window.removeEventListener('pointermove', moveCanvasInteraction);
    window.removeEventListener('pointerup', endCanvasInteraction);
    window.removeEventListener('pointercancel', endCanvasInteraction);
}
async function saveTemplate() {
    if (draftDesignMode.value) {
        templateError.value = '';
        if (!designTable.value || !templateElements.value.length) { templateError.value = 'Add at least one text, barcode, QR code, or border element to the canvas.'; notify('warning', 'Your canvas is empty', 'Add at least one design element before continuing.'); return; }
        draftTemplates.value[designTable.value.id] = { elements: structuredClone(templateElements.value) };
        if (designTableIndex.value + 1 < designTables.value.length) {
            designTableIndex.value += 1;
            templateElements.value = structuredClone(draftTemplates.value[designTables.value[designTableIndex.value]?.id]?.elements ?? []);
            templateMessage.value = '';
            return;
        }
        editorOpen.value = false;
        draftDesignMode.value = false;
        await upload();
        return;
    }
    if (!selectedBatch.value) return;
    templateSaving.value = true; templateError.value = ''; templateMessage.value = '';
    try {
        const updated = await request(`/api/batches/${selectedBatch.value.id}/template`, { method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ template:{ elements:templateElements.value } }) });
        selectedBatch.value = updated;
        selectedRecord.value = updated.records?.find((row) => row.id === selectedRecord.value?.id) ?? updated.records?.[0] ?? null;
        templateMessage.value = 'Design saved for this workbook.';
        notify('success', 'Design saved', 'The label design was updated for this workbook.');
    } catch (exception) { templateError.value = exception.message; notify('error', 'Could not save design', exception.message); }
    finally { templateSaving.value = false; }
}
async function deleteBatch(batch) {
    if (!deleteEnabled.value || deletingId.value) return;
    if (!await confirmDelete('Delete this folder?', `“${batch.name}” and all ${batch.row_count} label record(s) will be permanently deleted.`, 'Delete folder')) return;
    deletingId.value = `batch-${batch.id}`;
    deletingFolderName.value = batch.name;
    historyError.value = '';
    historyMessage.value = '';
    try {
        await request(`/api/batches/${batch.id}`, { method: 'DELETE' });
        batches.value = batches.value.filter((item) => item.id !== batch.id);
        if (selectedBatch.value?.id === batch.id) {
            selectedBatch.value = null;
            selectedRecord.value = null;
        }
        historyMessage.value = `Workbook folder “${batch.name}” and its labels were deleted.`;
        notify('success', 'Folder deleted', 'The workbook and its labels were removed.');
    } catch (exception) { historyError.value = exception.message; notify('error', 'Could not delete folder', exception.message); }
    finally { deletingId.value = null; deletingFolderName.value = ''; }
}
async function deleteRecord(record) {
    if (!deleteEnabled.value || deletingId.value) return;
    if (!await confirmDelete('Delete this label?', `The label for Row ${record.row_number} will be permanently deleted.`, 'Delete label')) return;
    const batchId = selectedBatch.value?.id;
    const previousIndex = recordIndex.value;
    const deletingSelectedRecord = selectedRecord.value?.id === record.id;
    deletingId.value = `record-${record.id}`;
    historyError.value = '';
    historyMessage.value = '';
    try {
        await request(`/api/records/${record.id}`, { method: 'DELETE' });
        if (batchId) {
            const remainingRecords = records.value.filter((item) => item.id !== record.id);
            const updatedBatch = { ...selectedBatch.value, row_count: remainingRecords.length, records: remainingRecords };
            selectedBatch.value = updatedBatch;
            batches.value = batches.value.map((item) => item.id === batchId ? { ...item, row_count: remainingRecords.length } : item);
            if (deletingSelectedRecord) {
                selectedRecord.value = null;
                const nextRecord = remainingRecords[Math.min(previousIndex, remainingRecords.length - 1)] ?? null;
                if (nextRecord) selectRecord(nextRecord);
            }
        }
        historyMessage.value = `Row ${record.row_number} label was deleted.`;
        notify('success', 'Label deleted', `Row ${record.row_number} was removed from this workbook.`);
    } catch (exception) { historyError.value = exception.message; notify('error', 'Could not delete label', exception.message); }
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
    const next = records.value[recordIndex.value + step]; if (next) selectRecord(next);
}
// Bump this when the server-side SVG renderer changes so immutable cached label images are refreshed.
const labelRenderRevision = `part-caption-spacing-origin-copy-20261127-v1:${new URL(import.meta.url).pathname.split('/').pop() ?? 'app'}`;
function labelUrl(record) { return `/api/records/${record.id}/label.svg?v=${encodeURIComponent(selectedBatch.value?.updated_at ?? record.updated_at ?? '')}&r=${encodeURIComponent(labelRenderRevision)}`; }
function onLabelImageLoad() { labelImageLoading.value = false; labelImageFailed.value = false; }
function onLabelImageError() { labelImageLoading.value = false; labelImageFailed.value = true; }
watch(() => selectedRecord.value?.id, (id) => {
    labelImageFailed.value = false;
    labelImageLoading.value = Boolean(id);
});
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
        canvas.height = Math.round(canvas.width * 124.5 / 174.5);
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
        notify('success', 'PNG downloaded', `Row ${record.row_number} label is saved to your downloads.`);
    } catch (exception) {
        downloadError.value = 'Could not create the PNG image. Please wait for the label to load and try again.';
        notify('error', 'PNG download failed', exception.message || downloadError.value);
    } finally {
        downloading.value = false;
    }
}

async function rasterizeLabelForPdf(record, svgMarkup, width = 1500) {
    const image = new Image();
    image.decoding = 'async';
    const svgUrl = URL.createObjectURL(new Blob([svgMarkup], { type: 'image/svg+xml;charset=utf-8' }));
    image.src = svgUrl;
    const loadImage = typeof image.decode === 'function'
        ? image.decode()
        : new Promise((resolve, reject) => {
            if (image.complete && image.naturalWidth > 0) return resolve();
            image.onload = resolve;
            image.onerror = () => reject(new Error(`The label image for Row ${record.row_number} could not be loaded.`));
        });
    let imageTimeout;
    try {
        await Promise.race([
            loadImage,
            new Promise((_, reject) => { imageTimeout = window.setTimeout(() => reject(new Error(`Preparing Row ${record.row_number} took too long. Please retry the PDF download.`)), 20000); }),
        ]);
    } finally {
        window.clearTimeout(imageTimeout);
        URL.revokeObjectURL(svgUrl);
    }

    const height = Math.round(width * 124.5 / 174.5);
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('This browser could not prepare a label image for the PDF.');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, width, height);
    context.drawImage(image, 0, 0, width, height);
    // Render at 218 DPI, enough for sharp printed codes while avoiding unnecessary
    // pixel work and large intermediate images at the old 337-DPI setting.
    // Let the browser's native encoder compress the label instead of copying
    // millions of pixels through JavaScript and deflating them by hand.
    const jpeg = await new Promise((resolve, reject) => canvas.toBlob(
        (blob) => blob ? resolve(blob) : reject(new Error('The browser could not encode a label image for the PDF.')),
        'image/jpeg',
        0.96,
    ));
    return { width, height, bytes: new Uint8Array(await jpeg.arrayBuffer()), jpeg: true };
}

function buildTwoUpPdf(images) {
    const pageWidth = 595.28; // A4 portrait, points
    const pageHeight = 841.89;
    const labelWidth = 494.72; // exact outer label width measured from the client PDF
    const labelHeight = 352.8; // exact outer label height measured from the client PDF
    const gap = 47.5; // space between the two guide labels
    const top = 17.5; // guide places the first label near the page top
    const x = 45.9; // guide's left edge on A4
    const pageCount = Math.ceil(images.length / 2);
    const objects = new Array(3 + pageCount * 4);
    const ascii = (value) => new TextEncoder().encode(value);
    const setObject = (id, data) => { objects[id] = typeof data === 'string' ? ascii(data) : data; };

    setObject(1, '<< /Type /Catalog /Pages 2 0 R >>');
    const pageIds = Array.from({ length: pageCount }, (_, index) => 3 + index * 4);
    setObject(2, `<< /Type /Pages /Kids [${pageIds.map((id) => `${id} 0 R`).join(' ')}] /Count ${pageCount} >>`);

    for (let page = 0; page < pageCount; page++) {
        const pageId = pageIds[page];
        const contentId = pageId + 1;
        const imageIds = [pageId + 2, pageId + 3];
        const firstIndex = page * 2;
        const pageImages = images.slice(firstIndex, firstIndex + 2);
        const blankImage = { width: 1, height: 1, bytes: new Uint8Array([120, 156, 99, 248, 255, 255, 63, 0, 5, 254, 2, 254]), jpeg: false };
        const xobjects = pageImages.map((_, index) => `/Im${index + 1} ${imageIds[index]} 0 R`).join(' ');
        setObject(pageId, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /XObject << ${xobjects} >> >> /Contents ${contentId} 0 R >>`);
        const commands = pageImages.map((_, index) => {
            const slotBottom = pageHeight - top - labelHeight - index * (labelHeight + gap);
            return `q ${labelWidth} 0 0 ${labelHeight} ${x} ${slotBottom} cm /Im${index + 1} Do Q`;
        }).join('\n');
        const content = ascii(commands);
        setObject(contentId, concatBytes([ascii(`<< /Length ${content.length} >>\nstream\n`), content, ascii('\nendstream')]));
        [pageImages[0], pageImages[1] ?? blankImage].forEach((image, index) => {
            const encoding = image.jpeg
                ? '/Filter /DCTDecode'
                : '/Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns ' + image.width + ' >>';
            setObject(imageIds[index], concatBytes([
                ascii(`<< /Type /XObject /Subtype /Image /Width ${image.width} /Height ${image.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 ${encoding} /Length ${image.bytes.length} >>\nstream\n`),
                image.bytes,
                ascii('\nendstream'),
            ]));
        });
    }

    const parts = [ascii('%PDF-1.4\n')];
    const offsets = [0];
    let length = parts[0].length;
    for (let id = 1; id < objects.length; id++) {
        offsets[id] = length;
        const object = concatBytes([ascii(`${id} 0 obj\n`), objects[id], ascii('\nendobj\n')]);
        parts.push(object);
        length += object.length;
    }
    const xrefOffset = length;
    let xref = `xref\n0 ${objects.length}\n0000000000 65535 f \n`;
    for (let id = 1; id < objects.length; id++) xref += `${String(offsets[id]).padStart(10, '0')} 00000 n \n`;
    xref += `trailer\n<< /Size ${objects.length} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;
    parts.push(ascii(xref));
    return new Blob(parts, { type: 'application/pdf' });
}

function concatBytes(parts) {
    const result = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0));
    let offset = 0;
    for (const part of parts) { result.set(part, offset); offset += part.length; }
    return result;
}

async function downloadBatchPdf(batch = selectedBatch.value) {
    if (!batch || pdfDownloading.value) return;
    pdfDownloading.value = true;
    pdfBatchId.value = batch.id;
    pdfBatchName.value = batch.name;
    try {
        let pdfBatch = batch;
        let batchRecords = selectedBatch.value?.id === batch.id ? records.value : null;
        if (!batchRecords) {
            pdfBatch = await request(`/api/batches/${batch.id}`);
            batchRecords = pdfBatch.records ?? [];
        }
        if (!batchRecords.length) throw new Error('This folder has no label records to export.');
        pdfProgress.value = { current: 0, total: batchRecords.length };
        const images = [];
        const labelsPerRequest = 12;
        const parallelRasterizations = 4;
        for (let start = 0; start < batchRecords.length; start += labelsPerRequest) {
            pdfStage.value = 'Loading labels';
            const pageGroup = batchRecords.slice(start, start + labelsPerRequest);
            const payload = await request(`/api/batches/${batch.id}/label-svgs?offset=${start}&limit=${labelsPerRequest}`);
            if (!Array.isArray(payload.labels) || payload.labels.length !== pageGroup.length || payload.labels.some((label, index) => label.id !== pageGroup[index].id)) {
                throw new Error(`The server returned ${payload.labels?.length ?? 0} label image(s) for rows ${start + 1}–${start + pageGroup.length}; expected ${pageGroup.length}. Refresh History and try again.`);
            }
            for (let groupStart = 0; groupStart < pageGroup.length; groupStart += parallelRasterizations) {
                const records = pageGroup.slice(groupStart, groupStart + parallelRasterizations);
                const labelSvgs = payload.labels.slice(groupStart, groupStart + parallelRasterizations);
                const results = await Promise.allSettled(records.map((record, index) => rasterizeLabelForPdf(record, labelSvgs[index].svg)));
                const failed = results.findIndex((result) => result.status === 'rejected');
                if (failed >= 0) {
                    const record = records[failed];
                    throw new Error(`PDF stopped at Row ${record.row_number}: ${results[failed].reason?.message ?? 'The label image could not be prepared.'}`);
                }
                images.push(...results.map((result) => result.value));
                pdfProgress.value = { current: images.length, total: batchRecords.length };
                // Give the browser a paint opportunity so the per-row progress is visible.
                await new Promise((resolve) => window.requestAnimationFrame(() => resolve()));
            }
        }
        pdfStage.value = 'Building PDF';
        await new Promise((resolve) => window.requestAnimationFrame(() => resolve()));
        const pdf = buildTwoUpPdf(images);
        const url = URL.createObjectURL(pdf);
        const link = document.createElement('a');
        const safeName = (pdfBatch.name || 'ogami-label').replace(/[<>:"/\\|?*\u0000-\u001F]/g, '-').trim() || 'ogami-label';
        link.href = url;
        link.download = `${safeName}-labels.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 60000);
        notify('success', 'PDF downloaded', `${images.length} labels from “${pdfBatch.name}” saved, two per A4 page (${Math.ceil(images.length / 2)} pages).`);
    } catch (exception) {
        notify('error', 'PDF download failed', exception.message || 'Could not create the PDF. Please try again.');
    } finally {
        pdfDownloading.value = false;
        pdfBatchId.value = null;
        pdfBatchName.value = '';
    }
}

onMounted(async () => {
    window.addEventListener('pageshow', handlePageShow);
    historyLoading.value = true;
    try { await refreshBatches(); } catch (exception) { error.value = exception.message; notify('error', 'Could not load History', exception.message); }
    finally { historyLoading.value = false; }
    try { deleteEnabled.value = (await request('/api/delete-status')).enabled; } catch { deleteEnabled.value = false; }
});
function handlePageShow(event) {
    if (!event.persisted) return;
    const previouslyOpenBatchId = selectedBatch.value?.id;
    historyLoading.value = true;
    error.value = '';
    selectedBatch.value = null;
    selectedRecord.value = null;
    refreshBatches()
        .then(async () => {
            deleteEnabled.value = (await request('/api/delete-status')).enabled;
            if (previouslyOpenBatchId && batches.value.some((batch) => batch.id === previouslyOpenBatchId)) {
                await openBatch(previouslyOpenBatchId);
            }
        })
        .catch((exception) => { error.value = exception.message; })
        .finally(() => { historyLoading.value = false; });
}
onBeforeUnmount(() => {
    window.removeEventListener('pageshow', handlePageShow);
    endCanvasInteraction();
});
</script>

<template>
  <div class="min-h-screen bg-slate-100 text-slate-900" @dragover.prevent @drop.prevent="editorOpen ? canvasDrop($event) : null">
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white">
      <div class="mx-auto flex h-16 max-w-7xl items-center gap-9 px-5">
        <div class="flex shrink-0 items-center gap-2 whitespace-nowrap text-lg font-extrabold"><img :src="'/ogami-mark.svg'" alt="" class="h-9 w-9"/><span>OGAMI <span class="text-blue-600">LABEL STUDIO</span></span></div>
        <nav class="flex h-full gap-2">
          <button v-for="page in ['upload','history']" :key="page" @click="activePage=page" class="border-b-2 px-4 capitalize" :class="activePage===page?'border-blue-600 font-bold text-blue-700':'border-transparent text-slate-500'">{{ page }}</button>
        </nav>
        <div class="ml-auto flex items-center gap-3 text-xs text-slate-500"><span>{{ userName }}</span><button @click="logout" class="rounded-lg border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50">Sign out</button></div>
      </div>
    </header>
    <main class="mx-auto max-w-7xl px-5 py-8">
      <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div><h1 class="text-3xl font-bold">{{ {upload:'Upload Excel',history:'Upload history'}[activePage] }}</h1><p class="mt-1 text-sm text-slate-500">Each selected table gets its own history folder and label canvas; every data row gets a label.</p></div>
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
        <div><b class="block">Upload complete and saved</b><span>{{ success.filename }} · {{ success.batchCount }} separate table canvas(es) · {{ success.count }} row labels · “{{ success.name }}”</span></div>
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
          <button v-if="file && !analysis" :disabled="analyzing" @click="analyzeWorkbook" class="mt-4 rounded-lg border border-blue-300 bg-blue-50 px-4 py-2.5 font-semibold text-blue-800 disabled:opacity-50">{{ analyzing ? 'Analyzing workbook…' : 'Upload & analyze Excel' }}</button>
          <div v-if="analysis" class="mt-4 space-y-4 rounded-xl border border-blue-100 bg-blue-50/60 p-4">
            <div class="flex flex-wrap items-center gap-2"><div class="mr-auto"><h3 class="font-bold">Workbook analysis</h3><p class="mt-1 text-sm text-slate-600">Found {{ analysis.sheets.length }} worksheet(s) with label data and {{ analysis.tables.length }} label table(s). Analysis only previews the workbook; labels are generated after you click Upload. Other formats open a blank design canvas for each selected table.</p></div><button @click="selectAllTables" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold">Select all</button><button @click="selectNoTables" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold">Clear</button></div>
            <p v-if="!analysis.tables.length" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">No table with a header and data rows was detected. Check that the table has column names in its first row.</p>
            <div v-else class="max-h-[520px] space-y-3 overflow-auto pr-1">
              <article v-for="table in analysis.tables" :key="table.id" class="rounded-xl border bg-white p-3" :class="selectedTableIds.includes(table.id)?'border-blue-400 ring-1 ring-blue-200':'border-slate-200'">
                <label class="flex cursor-pointer items-start gap-3"><input v-model="selectedTableIds" type="checkbox" :value="table.id" class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600"><span class="min-w-0 flex-1"><b class="block">{{ table.sheetName }} · {{ table.tableName }}</b><small class="block text-slate-500">{{ table.rowCount }} data rows · header row {{ table.headerRow }} · {{ table.headers.join(' · ') }}</small><small v-if="table.selectionNote" class="mt-1 block text-amber-700">{{ table.selectionNote }}</small></span></label>
                <details class="ml-7 mt-2"><summary class="cursor-pointer text-xs font-medium text-blue-700">Preview detected rows</summary><div class="mt-2 overflow-x-auto rounded border border-slate-200"><table class="min-w-full text-left text-xs"><thead class="bg-slate-100"><tr><th v-for="(header,index) in table.headers" :key="index" class="whitespace-nowrap px-2 py-1.5">{{ header }}</th></tr></thead><tbody><tr v-for="(row,index) in table.sample" :key="index"><td v-for="(value,col) in row" :key="col" class="max-w-40 truncate border-t border-slate-100 px-2 py-1.5">{{ value }}</td></tr></tbody></table></div></details>
                <div v-if="selectedTableIds.includes(table.id)&&table.codeColumns.length" class="ml-7 mt-3 space-y-2 rounded-lg bg-slate-50 p-3"><div><b class="text-xs">How should these codes appear on this canvas?</b><p class="text-xs text-slate-500">Your choice applies to this table only.</p></div><label v-for="column in table.codeColumns" :key="column.header" class="flex flex-wrap items-center justify-between gap-2 text-xs"><span>{{ column.header }} <small v-if="column.suggestedType" class="text-slate-500">· suggested {{ column.suggestedType === 'qr' ? 'QR code' : 'barcode' }}</small></span><select v-model="codeTypes[table.id][column.header]" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5"><option value="">Choose…</option><option value="barcode">Barcode</option><option value="qr">QR code</option><option value="text">Text only</option><option value="ignore">Do not show</option></select></label></div>
                <p v-if="selectedTableIds.includes(table.id)" class="ml-7 mt-2 text-xs text-slate-500">All columns can be added as variables on this table’s canvas.</p>
              </article>
            </div>
          </div>
          <label class="mt-4 block text-sm font-medium">Workbook / folder name<input v-model="batchName" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Excel 1" /></label>
          <button v-if="analysis && selectedTables.some((table)=>!table.legacyFormat)" :disabled="!file||!selectedTables.length||selectedTables.some((table)=>table.codeColumns.some((column)=>!codeTypes[table.id]?.[column.header]))||loading" @click="startDesign" class="mt-4 flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
            {{ loading?'Preparing…':'Edit label design' }}
          </button>
          <button v-else-if="analysis && selectedTables.length" :disabled="!file||!selectedTables.length||selectedTables.some((table)=>table.codeColumns.some((column)=>!codeTypes[table.id]?.[column.header]))||loading" @click="upload" class="mt-4 flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
            <span v-if="loading" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
            {{ loading?'Uploading and generating labels…':'Upload and generate labels' }}
          </button>
          <p v-if="loading" role="status" class="mt-3 text-sm text-blue-700">Uploading and validating your file. Keep this page open; confirmation will appear once it has been saved.</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-bold">Upload → Design → Records</h2><p class="mt-3 text-sm leading-6 text-slate-600">Analyzing a workbook never creates records. For the familiar Ogami format, review the table and click Upload and generate labels to use the original label layout. For a different format, choose which detected tables to use, select barcode or QR for code columns, then build a label from a blank canvas. Each selected table gets its own design.</p><p class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">Nothing is generated as a sample design. Add only the text, fields, barcodes, QR codes, and borders you want.</p></article>
      </section>

      <section v-if="activePage==='history'" class="space-y-4">
        <div v-if="!deleteEnabled" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Sign in again to manage your workbook history.</div>
        <div v-if="historyMessage" role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ historyMessage }}</div>
        <div v-if="historyError" role="alert" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ historyError }}</div>
        <div class="grid gap-5 lg:grid-cols-[390px_1fr]">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div v-if="historyLoading" role="status" aria-live="polite" class="space-y-3 p-3"><div class="flex items-center gap-3 rounded-lg bg-blue-50 p-4 text-sm font-medium text-blue-800"><span class="h-5 w-5 animate-spin rounded-full border-2 border-blue-200 border-t-blue-700" aria-hidden="true"></span>Loading your folders…</div><div v-for="item in 3" :key="item" class="animate-pulse rounded-xl border border-slate-200 p-4"><div class="h-4 w-2/3 rounded bg-slate-200"></div><div class="mt-3 h-3 w-1/2 rounded bg-slate-100"></div></div></div>
          <div v-else-if="!visibleBatches.length" class="p-8 text-center text-sm text-slate-500">No matching uploads yet.</div>
          <div v-for="batch in visibleBatches" :key="batch.id" class="mb-3 overflow-hidden rounded-xl border border-slate-200">
            <div class="flex flex-wrap items-center gap-2 bg-slate-50 p-2">
              <button @click="toggleBatch(batch.id)" :aria-expanded="selectedBatch?.id===batch.id" class="flex min-w-0 flex-1 items-center gap-3 rounded-lg p-2 text-left hover:bg-blue-50"><span class="min-w-0 flex-1"><b class="block truncate">📁 {{ batch.name }}</b><small class="mt-1 block truncate text-slate-500">{{ batch.row_count }} rows · {{ batch.original_filename }}</small></span><span class="grid h-8 w-8 shrink-0 place-items-center rounded-full border border-slate-200 bg-white text-lg text-slate-600">{{ selectedBatch?.id===batch.id?'⌄':'›' }}</span></button>
              <button @click="downloadBatchPdf(batch)" :disabled="pdfDownloading||deletingId!==null||loadingBatchId===batch.id" class="shrink-0 rounded-lg border border-indigo-200 bg-white px-2 py-2 text-xs font-semibold text-indigo-800 hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50" :title="`Download all ${batch.row_count} labels in this folder as a two-per-page PDF`">Download PDF</button>
              <button @click="deleteBatch(batch)" :disabled="!deleteEnabled||deletingId!==null||pdfDownloading" class="shrink-0 rounded-lg px-2 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40" :title="deleteEnabled ? 'Delete this workbook folder and all its labels' : 'Sign in again to delete your data'">Delete folder</button>
            </div>
            <div v-if="selectedBatch?.id===batch.id" class="max-h-[60vh] overflow-auto p-2">
              <div v-if="loadingBatchId===batch.id" role="status" class="flex items-center justify-center gap-2 rounded-lg bg-blue-50 px-4 py-6 text-sm text-blue-800"><span class="h-4 w-4 animate-spin rounded-full border-2 border-blue-200 border-t-blue-700" aria-hidden="true"></span>Loading rows…</div>
              <div v-else v-for="record in records" :key="record.id" class="flex items-center gap-1 border-b border-slate-100 hover:bg-blue-50" :class="selectedRecord?.id===record.id?'bg-blue-50':''">
                <button @click="selectRecord(record)" class="flex min-w-0 flex-1 items-center justify-between px-3 py-3 text-left"><span class="min-w-0"><b>Row {{ record.row_number }}</b><small class="mt-1 block truncate text-slate-500">{{ record.serial_no }} · {{ record.part_number }}</small></span><span class="text-xs text-blue-600">Open label ›</span></button>
                <button @click="deleteRecord(record)" :disabled="!deleteEnabled||deletingId!==null" class="inline-flex shrink-0 items-center gap-2 rounded-lg px-2 py-2 text-xs font-medium text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40" :title="deleteEnabled ? 'Delete this label record' : 'Sign in again to delete your data'"><span v-if="deletingId===`record-${record.id}`" class="h-3 w-3 animate-spin rounded-full border-2 border-red-200 border-t-red-700" aria-hidden="true"></span>{{ deletingId===`record-${record.id}` ? 'Deleting…' : 'Delete' }}</button>
              </div>
            </div>
          </div>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <template v-if="selectedRecord">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><span class="text-sm text-slate-500">{{ selectedBatch?.name }} / Row {{ selectedRecord.row_number }} · {{ recordIndex+1}} of {{ records.length }}</span><div class="flex flex-wrap gap-2"><button @click="moveRecord(-1)" :disabled="recordIndex<=0||downloading||pdfDownloading||loadingRecordId!==null" class="rounded-lg bg-slate-100 px-3 py-2 disabled:opacity-40">‹ Previous</button><button @click="moveRecord(1)" :disabled="recordIndex>=records.length-1||downloading||pdfDownloading||loadingRecordId!==null" class="rounded-lg bg-slate-100 px-3 py-2 disabled:opacity-40">Next ›</button><button @click="openEditor" :disabled="pdfDownloading||loadingRecordId!==null" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 font-medium text-indigo-800 hover:bg-indigo-100 disabled:opacity-50">Edit design</button><button @click="printLabel" :disabled="pdfDownloading" class="rounded-lg border border-slate-300 bg-white px-3 py-2 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">Print label</button><button @click="downloadLabel(selectedRecord)" :disabled="downloading||pdfDownloading||labelImageLoading||labelImageFailed" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-white disabled:opacity-50"><span v-if="downloading" class="h-4 w-4 animate-spin rounded-full border-2 border-blue-200 border-t-white" aria-hidden="true"></span>{{ downloading ? 'Preparing PNG…' : 'Download PNG' }}</button></div></div>
            <p class="mb-2 text-xs text-slate-500">Label preview · Row {{ selectedRecord.row_number }} · Serial no. {{ selectedRecord.serial_no }}</p>
            <div class="relative mx-auto min-h-[180px] w-full max-w-[900px]" aria-live="polite">
              <div v-if="labelImageLoading" class="absolute inset-0 z-10 grid min-h-[180px] place-items-center rounded-lg border border-slate-200 bg-white/90 text-sm text-slate-600"><span class="flex items-center gap-3"><span class="h-5 w-5 animate-spin rounded-full border-2 border-blue-200 border-t-blue-600" aria-hidden="true"></span>Loading label image…</span></div>
              <div v-else-if="labelImageFailed" class="grid min-h-[180px] place-items-center rounded-lg border border-red-200 bg-red-50 px-4 text-center text-sm text-red-700">Could not load this label image. Reopen the row or refresh History.</div>
              <img ref="labelImage" :key="selectedRecord.id" :src="labelUrl(selectedRecord)" @load="onLabelImageLoad" @error="onLabelImageError" class="mx-auto block w-full max-w-[900px] transition-opacity" :class="labelImageLoading||labelImageFailed ? 'opacity-0' : 'opacity-100'" alt="Accurately encoded Ogami product label" />
            </div>
            <div class="print-label" aria-hidden="true"><img :src="labelUrl(selectedRecord)" alt="" /></div>
            <p v-if="downloadError" role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ downloadError }}</p>
            <div class="mt-4 grid gap-2 rounded-lg bg-slate-50 p-4 text-xs sm:grid-cols-2"><div>QR CODE: <code>{{ selectedRecord.qr_code }}</code></div><div>SERIAL CODE: <code>{{ selectedRecord.serial_code }}</code></div><div>QUANTITY CODE: <code>{{ selectedRecord.quantity_code }}</code></div><div>PART NUMBER CODE: <code>{{ selectedRecord.part_number_code }}</code></div><div>SUPPLIER CODE: <code>{{ selectedRecord.supplier_code }}</code></div></div>
          </template>
          <div v-else class="p-14 text-center text-slate-500">Select a row to view its label.</div>
        </article>
        </div>
      </section>
    </main>
    <div v-if="pdfDownloading||deletingFolder" class="fixed inset-0 z-[60] grid place-items-center bg-slate-950/40 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-live="polite" aria-busy="true" :aria-label="pdfDownloading ? 'Preparing folder PDF' : 'Deleting workbook folder'">
      <section class="w-full max-w-md rounded-2xl bg-white p-7 text-center shadow-2xl ring-1 ring-slate-200">
        <div class="relative mx-auto mb-5 grid h-16 w-16 place-items-center"><span class="absolute inset-0 animate-ping rounded-full bg-blue-100 opacity-60" style="animation-duration: 2s"></span><span class="relative h-12 w-12 animate-spin rounded-full border-[5px] border-blue-100 border-t-blue-600" aria-hidden="true"></span></div>
        <h2 class="text-xl font-bold text-slate-900">{{ pdfDownloading ? 'Preparing your PDF' : 'Deleting folder' }}</h2>
        <p class="mt-2 break-words text-sm text-slate-600">{{ pdfDownloading ? pdfBatchName : deletingFolderName }}</p>
        <template v-if="pdfDownloading">
          <p class="mt-4 text-sm font-medium text-blue-700">{{ pdfStage === 'Building PDF' ? 'Assembling the two-per-page PDF…' : `Loading label ${Math.min(pdfProgress.current + 1, pdfProgress.total || 1)} of ${pdfProgress.total || '…'}…` }}</p>
          <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-blue-600 transition-[width] duration-300" :style="{ width: `${pdfProgressPercent}%` }"></div></div>
          <p class="mt-2 text-xs text-slate-500">{{ pdfProgressPercent }}% complete · Keep this page open.</p>
        </template>
        <p v-else class="mt-4 text-sm text-slate-600">Removing this workbook and its label records from your account…</p>
      </section>
    </div>
    <div v-if="editorOpen" class="fixed inset-0 z-40 flex flex-col bg-slate-100" role="dialog" aria-modal="true" aria-label="Label design editor">
      <header class="flex flex-wrap items-center gap-3 border-b border-slate-200 bg-white px-5 py-3"><div class="mr-auto"><b class="text-lg">{{ draftDesignMode ? `Design ${designTableIndex + 1} of ${designTables.length} · ${designTable?.sheetName} / ${designTable?.tableName}` : 'Label design editor' }}</b><p class="text-xs text-slate-500">{{ draftDesignMode ? 'Start with a blank canvas. Add and arrange the fields, barcodes, QR codes, and borders you want.' : 'Double-click static text on the canvas to edit it. Drag items to move or resize them.' }}</p></div><span v-if="templateMessage" class="text-sm text-emerald-700">{{ templateMessage }}</span><span v-if="templateError" class="text-sm text-red-700">{{ templateError }}</span><button @click="saveTemplate" :disabled="templateSaving" class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white disabled:opacity-50">{{ templateSaving ? 'Saving…' : (draftDesignMode ? (designTableIndex + 1 < designTables.length ? 'Save design & next table' : 'Create records') : 'Save design') }}</button><button @click="editorOpen=false; draftDesignMode=false" class="rounded-lg border border-slate-300 px-4 py-2">{{ draftDesignMode ? 'Cancel' : 'Close' }}</button></header>
      <div class="grid min-h-0 flex-1 lg:grid-cols-[270px_minmax(0,1fr)_300px]">
        <aside class="overflow-auto border-r border-slate-200 bg-white p-4">
          <h3 class="mb-2 font-bold">Fields</h3>
          <p class="mb-4 text-xs leading-5 text-slate-500"><b>Text</b> prints readable characters. <b>QR code</b> creates a scannable square image containing the selected field’s value.</p>
          <div class="space-y-2">
            <div v-for="[field,label] in availableFields" :key="field" class="rounded-lg border border-slate-200 p-2">
              <button draggable="true" @dragstart="(event)=>{event.dataTransfer.setData('application/ogami-type','text');event.dataTransfer.setData('application/ogami-field',field)}" @click="addCanvasElement('text',field)" class="block w-full cursor-grab text-left text-sm hover:text-blue-700">Add readable text <small class="block truncate text-slate-500">{{ label }}</small></button>
              <div class="mt-2 flex gap-3 text-xs"><button @click="addCanvasElement('barcode',field)" class="font-medium text-blue-700 underline">Add barcode</button><button @click="addCanvasElement('qr',field)" class="font-medium text-blue-700 underline">Add scannable QR</button></div>
            </div>
          </div>
          <button @click="addCanvasElement('text')" class="mt-5 w-full rounded-lg bg-slate-100 p-2 text-sm font-semibold">+ Add static text</button>
          <button @click="addCanvasElement('box')" class="mt-2 w-full rounded-lg border border-slate-200 p-2 text-sm font-semibold">+ Add border</button>
        </aside>
        <section class="flex min-w-0 items-center justify-center overflow-auto p-5 lg:p-10" @dragover.prevent @drop.prevent="canvasDrop($event)">
          <div ref="canvasElement" class="relative aspect-[760/535] w-full max-w-[1100px] shrink-0 overflow-hidden bg-white shadow-xl ring-1 ring-slate-300" @dragover.prevent @drop.prevent.stop="canvasDrop($event)">
            <div class="pointer-events-none absolute inset-0 border-2 border-dashed border-slate-300"></div>
            <div v-for="element in templateElements" :key="element.id" @pointerdown="beginCanvasInteraction($event,element,'move')" class="absolute cursor-move select-none overflow-hidden border border-dashed border-blue-300/80 px-1 text-slate-900 hover:border-blue-600" :class="element.type==='box'?'bg-transparent':'bg-white/30'" :style="{left:`${element.x/760*100}%`,top:`${element.y/535*100}%`,width:`${element.w/760*100}%`,height:`${element.h/535*100}%`,fontSize:`${element.fontSize||20}px`}">
              <span v-if="element.type==='box'" class="pointer-events-none block h-full w-full border border-slate-800"></span>
              <span v-else-if="element.type==='qr'" class="flex h-full w-full items-center justify-center bg-white p-1"><img v-if="qrPreviews[element.id]" :src="qrPreviews[element.id]" class="pointer-events-none block h-full w-full object-contain" alt="Scannable QR code preview"><span v-else class="px-2 text-center text-xs text-slate-500">{{ elementValue(element) ? 'QR preview unavailable for this value' : 'No value in the preview row' }}</span></span>
              <span v-else-if="element.type==='barcode'" class="pointer-events-none grid h-full place-items-center bg-white text-3xl tracking-widest">▥ ▥ ▥</span>
              <span v-else-if="element.field" class="pointer-events-none block truncate" :style="{textAlign:element.align||'left'}">{{ elementValue(element) || `[${element.field}]` }}</span>
              <span v-else :data-text-widget="element.id" :contenteditable="editingTextId===element.id" @pointerdown="editingTextId===element.id && $event.stopPropagation()" @dblclick.stop="beginInlineTextEdit($event,element)" @input="updateInlineText($event,element)" @blur="finishInlineTextEdit" @keydown.enter.prevent="finishInlineTextEdit" class="block min-h-5 cursor-text whitespace-pre-wrap break-words outline-none" :style="{textAlign:element.align||'left'}">{{ element.label || 'Double-click to edit' }}</span>
              <button type="button" title="Drag to resize" aria-label="Resize design element" class="absolute bottom-0 right-0 h-4 w-4 touch-none cursor-nwse-resize border-l border-t border-blue-600 bg-white" @pointerdown.stop="beginCanvasInteraction($event,element,'resize')" @click.stop></button>
            </div>
          </div>
        </section>
        <aside class="overflow-auto border-l border-slate-200 bg-white p-4">
          <h3 class="font-bold">Design elements</h3>
          <p class="mt-1 text-xs text-slate-500">Drag an item to move it. Drag its bottom-right corner to resize it; no measurement guesswork needed.</p>
          <div class="mt-4 space-y-3">
            <div v-for="element in templateElements" :key="element.id" class="rounded-xl border border-slate-200 p-3">
              <div class="mb-2 flex items-center justify-between"><b class="text-sm">{{ element.type==='text' ? 'Readable text' : element.type==='qr' ? 'Scannable QR code' : element.type==='box' ? 'Resizable border' : 'Barcode' }}</b><button @click="templateElements=templateElements.filter(item=>item.id!==element.id)" class="text-xs text-red-600">Remove</button></div>
              <small v-if="element.type==='qr'" class="mb-2 block text-xs text-slate-500">QR image encodes the value from the selected Excel column.</small>
              <small v-else-if="element.type==='text'&&element.field" class="mb-2 block text-xs text-slate-500">Prints the value as readable text.</small>
              <select v-if="(element.type==='qr'||element.type==='barcode'||(element.type==='text'&&element.field))" v-model="element.field" class="mb-2 w-full rounded border border-slate-300 px-2 py-1 text-xs"><option v-for="[field,label] in availableFields" :key="field" :value="field">{{ label }}</option></select>
              <label v-if="element.type==='text'" class="mb-2 block text-xs text-slate-500">Text alignment<select v-model="element.align" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-xs"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></label>
              <div class="grid grid-cols-2 gap-2"><label v-for="key in ['x','y','w','h']" :key="key" class="text-xs capitalize text-slate-500">{{ key }}<input v-model.number="element[key]" type="number" min="0" :max="key==='w'?760-element.x:key==='h'?535-element.y:key==='x'?760-element.w:535-element.h" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"></label></div>
              <label v-if="element.type==='text'" class="mt-2 block text-xs text-slate-500">Font size<input v-model.number="element.fontSize" type="number" min="8" max="64" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"></label>
            </div>
          </div>
          <p v-if="!templateElements.length" class="mt-4 text-sm text-slate-500">Your canvas is blank. Add an Excel field or a border to start.</p>
        </aside>
      </div>
    </div>
  </div>
</template>
