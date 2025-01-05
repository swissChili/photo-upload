<?php

/**
 * Single-File Photo Upload
 * Copyright (c) 2025 swissChili <swisschili.sh>
 *
 * Released under the GNU Affero General Public License, version 3. (AGPLv3).
 *
 * WARNING: This code is very likely insecure. Use ONLY on a private network.
 * DO NOT EXPOSE THIS CODE TO THE INTERNET.
 *
 * These constants affect where images are placed. They can be relative or full
 * paths.
 *
 * The _SIZE constants are in bytes. Keep in mind that you need to set a corresponding
 * max upload size in the php.ini file or uploads will fail with error 1.
 */

const IMAGES_DIR = 'images';
const CHUNK_DIR = 'chunks';
const CHUNK_SIZE = 1024 * 1024;
const MAX_FILE_SIZE = 1024 * 1024 * 1024;



error_reporting(E_ALL);

$error_types = array(
    0 => 'There is no error, the file uploaded with success',
    1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
    2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
    3 => 'The uploaded file was only partially uploaded',
    4 => 'No file was uploaded',
    6 => 'Missing a temporary folder',
    7 => 'Failed to write file to disk.',
    8 => 'A PHP extension stopped the file upload.',
);

function handle_uploaded_file($tmpPath, $name) {
    if (!file_exists($tmpPath)) {
        throw new Exception('Temp file not found: ' . $tmpPath);
    }

    $mimeType = mime_content_type($tmpPath);

    // Rest of the code remains the same...
    if (!str_starts_with($mimeType, 'image/') && !str_starts_with($mimeType, 'video/')) {
        unlink($tmpPath);
        throw new Exception('Non-image file uploaded');
    }

    $exif = @exif_read_data($tmpPath);

    if (!$exif) {
        $exif = exiftool($tmpPath);
    }

    if ($exif && isset($exif['DateTimeOriginal'])) {
        $date = DateTime::createFromFormat('Y:m:d H:i:s', $exif['DateTimeOriginal']);
    } else {
        $date = new DateTime();
        $date->setTimestamp(filemtime($tmpPath));
    }

    $year = $date->format('Y');
    $month = $date->format('m');
    $day = $date->format('d');
    $path = IMAGES_DIR . "/$year/$month/$day";

    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }

    $destination = $path . '/' . basename($name);

    if (file_exists($destination)) {
        $tmpHash = hash_file('md5', $tmpPath);
        $destHash = hash_file('md5', $destination);
        unlink($tmpPath);

        if ($tmpHash != $destHash) {
            throw new Exception('File with same path already exists but has different content');
        } else {
            return ['status' => 'success',
                'path' => "$year/$month/$day",
                'message' => 'duplicate'];
        }
    } else if (rename($tmpPath, $destination)) {
        chmod($destination, 0644);
        return ['status' => 'success', 'path' => "$year/$month/$day"];
    } else {
        throw new Exception("Failed to move uploaded file from $tmpPath to $destination");
    }
}

function lock($path, $type=LOCK_EX) {
    $fd = fopen($path, 'r+');
    while (!flock($fd, $type)) {
    }
    return $fd;
}

function unlock($fd) {
    flock($fd, LOCK_UN);
    fclose($fd);
}

function create_file_sized($path, $size) {
    $fd = fopen($path, 'w');
    fseek($fd, $size - 1, SEEK_CUR);
    fwrite($fd, '\0');
    fclose($fd);
}

function start_chunked_upload($id, $name, $size) {
    $base = CHUNK_DIR . "/$id";
    $lockfile = $base . ".lock";
    $chunkfile = $base . ".chunk";
    $tmpfile = $base . ".tmp";

    if (file_exists($lockfile)) {
        throw new Exception("Can't start chunk upload: chunk $id already exists");
    } else if ($size > MAX_FILE_SIZE) {
        throw new Exception("File $size is too big");
    }

    if (!file_exists(CHUNK_DIR)) {
        mkdir(CHUNK_DIR, 0777, true);
    }


    touch($lockfile);
    $lockfd = lock($lockfile, LOCK_EX);

    create_file_sized($tmpfile, $size);

    $num_chunks = (int)ceil($size / CHUNK_SIZE);
    $chunk_info = array(
        'id' => $id,
        'name' => $name,
        'chunks' => array_fill(0, $num_chunks, false)
    );
    file_put_contents($chunkfile, json_encode($chunk_info));

    unlock($lockfd);

    return $chunk_info;
}

function upload_chunk($id, $chunk_index, $upload_path) {
    $base = CHUNK_DIR . "/$id";
    $lockfile = $base . ".lock";
    $chunkfile = $base . ".chunk";
    $tmpfile = $base . ".tmp";

    $lockfd = lock($lockfile, LOCK_EX);
    $chunk = json_decode(file_get_contents($chunkfile), true);

    if (count($chunk['chunks']) <= $chunk_index) {
        throw new Exception("chunk index $chunk_index out of bounds");
    }

    if (!$chunk['chunks'][$chunk_index]) {
        // not already uploaded
        $upload_fd = fopen($upload_path, 'rb');
        $tmp_fd = fopen($tmpfile, 'r+b');
        fseek($tmp_fd, $chunk_index * CHUNK_SIZE);
        stream_copy_to_stream($upload_fd, $tmp_fd, CHUNK_SIZE);

        fclose($tmp_fd);
        fclose($upload_fd);

        $chunk['chunks'][$chunk_index] = true;
        file_put_contents($chunkfile, json_encode($chunk));
    }

    unlock($lockfd);

    return $chunk;
}

function finish_chunked_upload($id) {
    $base = CHUNK_DIR . "/$id";
    $lockfile = $base . ".lock";
    $chunkfile = $base . ".chunk";
    $tmpfile = $base . ".tmp";

    $lockfd = lock($lockfile, LOCK_EX);
    $chunk = json_decode(file_get_contents($chunkfile), true);

    $done = !in_array(false, $chunk['chunks'], true);

    if (!$done) {
        throw new Exception("Chunked upload isn't done");
    }

    $res = handle_uploaded_file($tmpfile, $chunk['name']); // this unlinks the tmpfile

    unlink($chunkfile);
    unlock($lockfd);
    unlink($lockfile);

    return array_merge($res, $chunk);
}

function exiftool($path) {
    $desc = array(
        1 => array('pipe', 'w')
    );

    $cmd = array('exiftool', '-j', $path);
    $proc = proc_open($cmd, $desc, $pipes);
    if (is_resource($proc)) {
        $json = json_decode(stream_get_contents($pipes[1]), true);
        fclose($pipes[1]);
        $ret = proc_close($proc);

        if ($ret == 0) {
            return $json[0];
        }
    }

    return array();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        header('Content-Type: application/json');

        if (isset($_POST['chunk_name']) && isset($_POST['chunk_size'])) {
            $id = uniqid("c");
            $chunk = start_chunked_upload($id, $_POST['chunk_name'], intval($_POST['chunk_size']));
            $chunk['status'] = 'success';
            echo json_encode($chunk);
        } else if (isset($_POST['chunk_id']) && isset($_POST['chunk_index'])) {
            $id = $_POST['chunk_id'];
            $index = intval($_POST['chunk_index']);
            if (empty($_FILES)) {
                throw new Exception('No files received');
            }

            if (!isset($_FILES['file'])) {
                throw new Exception('Not set file');
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Upload error: ' . $error_types[$_FILES['file']['error']]);
            }

            $tmp = $_FILES['file']['tmp_name'];
            $chunk = upload_chunk($id, $index, $tmp);
            $chunk['status'] = 'success';

            if (!in_array(false, $chunk['chunks'], true)) {
                echo json_encode(finish_chunked_upload($id));
            } else {
                echo json_encode($chunk);
            }
        }
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    header('Content-Type: text/html');
    http_response_code(200);

?><!DOCTYPE html>
<head>
    <title>Photo Upload</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎯</text></svg>">
</head>
<body>

    <main>
        <h1>Photo Upload</h1>

        <p>
            Select photos or videos to upload them to the server and sort them by date (YYYY/MM/DD). The max file size is <?= MAX_FILE_SIZE / (1024 * 1024) ?> MB. Uploads are automatically checked for duplicates and name collisions.
        </p>

        <p>
            Made by <a href="https://swisschili.sh">swissChili</a>.
        </p>

        <p>
            <div class="drop-area">
                <label for="fileInput">Select Photos</label>
                <input type="file" id="fileInput" multiple accept="image/*,video/*" multiple />
            </div>
        </p>

        <table>
            <thead>
                <td>Image</td>
                <td>Status</td>
            </thead>
            <tbody>
            </tbody>
        </table>
    </main>

    <div class="details">
        <div class="preview-container">
            <img id=preview>
        </div>
        <div class="form">
            <span>File name</span> <span id="detailsName"></span>
        </div>
        <p id="json"></p>

        <div class="progress-container">
            <progress id="global-progress" value="0" max="0">
            </progress>
            <span id="global-progress-percent"></span>
        </div>
    </div>
</body>

<style>

html {
    width: 100%;
    height: 100%;
    position: fixed;
}

body {
    font-family: sans-serif;
    margin: 0;
    padding: 0;
    height: 100%;
    font-size: 12pt;
    display: grid;
    grid-template-columns: auto min-content;
    box-sizing: border-box;
    overflow: none;
}

h1 {
    color: teal;
}

table {
    width: 100%;
    border: 1px solid color-mix(in srgb, grey, transparent);
    font-size: 12pt;
}

main {
    padding: 1em;
    border-right: 1px solid teal;
    overflow: auto;
}

.preview-container {
    width: 100%;
    height: 300px;
    margin-bottom: 1em;
    display: grid;
    place-items: center center;
}

#preview {
    max-width: 100%;
    max-height: 300px;
    box-shadow: 2px 2px 8px rgba(0, 0, 0, 0.25);
    display: none;
}

#preview.loaded {
    display: block;
}

.details {
    padding: 1em;
    width: calc(300px + 2em);
}

@media screen and (max-width: 500px) {
    body {
        grid-template-columns: 1fr;
        grid-template-rows: auto min-content;
    }

    main {
        border-right: none;
        border-bottom: 1px solid teal;
    }

    .details {
        width: calc(100% - 2em);
    }

    .preview-container {
        height: 20vh;
    }

    #preview {
        max-height: 20vh;
    }
}

.form  {
    display: grid;
    grid-template-columns: min-content auto;
    grid-gap: 4px;
}

.form span:nth-child(odd) {
    text-wrap: nowrap;
    font-weight: bold;
}

.form span:nth-child(even) {
    text-align: right;
}

#json {
    word-break: break-all;
}

tr:hover {
    background: color-mix(in srgb, teal 10%, transparent 90%);
}

thead {
    font-weight: bold;
}

.drop-area {
    top: -1em;
    position: sticky;
    border: 1px solid teal;
    background: color-mix(in srgb, teal 20%, white);
    display: grid;
    grid-template-columns: 1fr;
    grid-template-rows: 1fr;
}

.drop-area.drag {
    background: color-mix(in srgb, teal 40%, white);
}

.drop-area input {
    display: none;
}

.drop-area label {
    text-align: center;
    font-weight: bold;
    padding: 1em;
}

.drop-area span {
    font-size: 48pt;
    color: white;
    text-shadow: 1px 1px 4px teal;
}

.progress-container {
    display: grid;
    grid-template-columns: 1fr min-content;
    grid-gap: 4px;
}

.progress-container progress {
    width: 100%;
}

</style>

<script>

const transferQueue = [];
let transfersInProgress = 0;
const MAX_TRANSFERS = 32;

function continueTransfers() {
    console.log("transfers in progress", transfersInProgress, "max transfers", MAX_TRANSFERS);
    while (transfersInProgress < MAX_TRANSFERS && transferQueue.length > 0) {
        const next = transferQueue.shift();
        transfersInProgress += 1;

        next().then(() => {
            transfersInProgress -= 1;
            continueTransfers();
        });
    }
}

function enqueueTransfer(transferFunction) {
    transferQueue.push(transferFunction);

    continueTransfers();
}

async function upload(file, statusCallback) {
    const size = file.size;
    const chunkSize = Math.min(<?= CHUNK_SIZE ?>, size);
    const numChunks = Math.ceil(size / chunkSize);

    const formData = new FormData();
    formData.append('chunk_name', file.name);
    formData.append('chunk_size', size);
    const res = await fetch('', {
        method: 'POST',
        body: formData
    });

    const info = await res.json();

    statusCallback(info);

    async function uploadChunk(blob, chunkIndex) {
        const formData = new FormData();
        formData.append('file', blob, file.name);
        formData.append('chunk_index', String(chunkIndex));
        formData.append('chunk_id', String(info.id));
        const res = await fetch('', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        statusCallback(json);
    }

    for (let i = 0; i < numChunks; i++) {
        const blob = file.slice(chunkSize * i, chunkSize * (i + 1));
        enqueueTransfer(() => uploadChunk(blob, i));
    }
}


////////////////////
// USER INTERFACE //
////////////////////

const $ = document.querySelector.bind(document);
const $tb = $('tbody');
const $preview = $('#preview');
const $detailsName = $('#detailsName');
const $drop = $('.drop-area label');
const $body = $('body');
const $globalProgress = $('#global-progress');
const $percent = $('#global-progress-percent');

const globalProgress = {};

function percentToString(frac) {
    if (frac === 1) {
        return ' ✅';
    } else {
        return ' ' + String(Math.ceil(frac * 100)) + '%';
    }
}

function renderGlobalProgress() {
    let done = 0;
    let total = 0;
    for (const chunks of Object.values(globalProgress)) {
        done += chunks.filter(x => x).length;
        total += chunks.length;
    }

    $globalProgress.value = done;
    $globalProgress.max = total;
    $percent.innerText = percentToString(done / total);
}

function renderStatus(status, $el) {
    if (status.status == 'success') {
        globalProgress[status.id] = status.chunks;
        renderGlobalProgress();

        const $p = document.createElement('progress');
        const done = status.chunks.filter(x => x).length;
        $p.value = done;
        const total = status.chunks.length;
        $p.max = total;
        $el.innerHTML = '';
        $el.appendChild($p);
        const $done = document.createElement('span');
        $done.innerText = percentToString(done / total) + ' ' + status.path + ' ' + (status?.message || '');

        $el.appendChild($done);

    } else {
        $el.innerText = '⛔ ' + status.message;
    }
}

async function uploadFiles(files) {
    const promises = [];

    for (let file of files) {
        const formData = new FormData();
        formData.append('file', file);

        const url = URL.createObjectURL(file);
        
        const $tr = document.createElement('tr');
        const $name = document.createElement('td');
        const $status = document.createElement('td');
        $tr.appendChild($name);
        $tr.appendChild($status);

        $name.textContent = file.name;
        $status.textContent = '...';

        $tb.appendChild($tr);

        $tr.addEventListener('mouseover', e => {
            e.preventDefault();
            $preview.src = url;
            $detailsName.textContent = file.name;
        });
        
        async function uploadThisFile() {
            try {
                await upload(file, status => {
                    console.log("upload status update", status)
                    renderStatus(status, $status);
                });
            } catch (error) {
                $status.textContent = error.message;
                console.error(error);
            }
        }

        promises.push(uploadThisFile());
    }

    await Promise.all(promises);
};

document.getElementById('fileInput').addEventListener('change', (e) => {
    const files = Array.from(e.target.files);
    if (files.length > 0) {
        uploadFiles(files);
    }
});

window.addEventListener('drop', e => e.preventDefault());
window.addEventListener('dragover', e => e.preventDefault());

function flatten(arr) {
    return arr.reduce((a, b) => a.concat(b), []);
}

async function addDirectory(entry) {
    console.log("addDirectory", entry);

    if (entry.isDirectory) {
        var reader = entry.createReader();
        const promises = [];
        await new Promise(done => reader.readEntries(entries => {
            for (const child of entries) {
                promises.push(addDirectory(child));
            }
            done();
        }));
        const results = await Promise.all(promises);
        console.log("got subresults", results);
        const files = flatten(results);
        console.log("returning", files);
        return files;
    } else {
        const file = [await new Promise(entry.file.bind(entry))];
        console.log("reached file", file);
        return file;
    }
}

$drop.addEventListener('drop', async e => {
    e.preventDefault();

    console.log('file dropped');

    console.log(e.dataTransfer);

    let files = [];
    if (e.dataTransfer.items) {
        const promises = Array.from(e.dataTransfer.items)
            .filter(item => item.kind === 'file')
            .map(item => item.webkitGetAsEntry())
            .filter(entry => entry)
            .map(entry => addDirectory(entry));

        files = flatten(await Promise.all(promises));
    } else {
        files = e.dataTransfer.files;
    }

    console.log('got files', files);
    console.log(typeof files)

    if (files.length > 0) {
        console.log(`uploading ${files.length} files`);
        uploadFiles(files);
    }

    $('.drop-area').classList.remove('drag');
});

$drop.addEventListener('dragenter', e => {
    e.preventDefault();
    $('.drop-area').classList.add('drag');
});

$drop.addEventListener('dragleave', e => {
    e.preventDefault();
    $('.drop-area').classList.remove('drag');
});

$drop.addEventListener('dragover', e => e.preventDefault());

$preview.addEventListener('load', e => {
    $preview.classList.add('loaded');
});

$preview.addEventListener('error', e => {
    $preview.classList.remove('loaded');
});
</script>

<?php
} else {
    http_response_code(502);
    echo '502';
}
?>