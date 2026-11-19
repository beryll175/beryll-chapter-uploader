(async()=>{
"use strict";

/* OMNI → BERYLL EXPORTER V3 */

const API="https://api.readomni.com/translation/";
const DB="omni2beryll-v2";

if(document.getElementById("o2b-panel")){
 alert("Exporter is already open.");
 return;
}

let db,threadId,firstId,novelTitle="Omni Export";
let records=[],selected=new Set();
let scanning=false,running=false,paused=false;
let filter="all",mode="all",batchSize=50;
let rangeStart=1,rangeEnd=999999;
let downloadMode="both";

const $=id=>document.getElementById(id);

const panel=document.createElement("div");
panel.id="o2b-panel";
panel.style.cssText=`
position:fixed;z-index:2147483647;
top:6px;left:6px;right:6px;bottom:6px;
overflow:auto;background:#171717;color:white;
padding:15px;border:2px solid #ff9800;
border-radius:14px;font:14px Arial,sans-serif;
box-shadow:0 5px 30px #0009;
`;

panel.innerHTML=`
<div style="font-size:21px;font-weight:bold;color:#ff9800">
Omni → Beryll Exporter
<span style="font-size:11px;color:#aaa">V3.0</span>
</div>

<div id="o2b-novel" style="
background:#292929;padding:12px;border-radius:9px;
margin:12px 0;line-height:1.6">
Identifying novel...
</div>

<details open style="margin:12px 0">
<summary style="font-size:16px;font-weight:bold;padding:8px">
⚙️ Scraping settings
</summary>

<div style="padding:10px;background:#292929;border-radius:9px">

<label>Chapter status</label>
<select id="o2b-filter" style="width:100%;padding:11px;margin:7px 0">
<option value="all">All chapters</option>
<option value="finished">Finished only</option>
<option value="unfinished">Unfinished only</option>
</select>

<label>Chapter selection</label>
<select id="o2b-mode" style="width:100%;padding:11px;margin:7px 0">
<option value="all">Entire novel</option>
<option value="range">Specific chapter range</option>
<option value="individual">Select individual chapters</option>
</select>

<div id="o2b-range" style="display:none">
<label>Starting chapter position</label>
<input id="o2b-from" type="number" min="1" value="1"
style="width:100%;padding:10px;margin:5px 0">

<label>Ending chapter position</label>
<input id="o2b-to" type="number" min="1" value="100"
style="width:100%;padding:10px;margin:5px 0">
</div>

<div id="o2b-individual" style="display:none">
<div style="margin:10px 0">
<button id="o2b-selectall">Select all visible</button>
<button id="o2b-clear">Clear selection</button>
</div>
<div id="o2b-list" style="
max-height:220px;overflow:auto;
background:#171717;padding:8px;border-radius:8px">
Scan the chapter list first.
</div>
</div>

<label>Batch size</label>
<select id="o2b-batch" style="width:100%;padding:11px;margin:7px 0">
<option value="25">25 chapters</option>
<option value="50" selected>50 chapters</option>
<option value="100">100 chapters</option>
<option value="200">200 chapters</option>
</select>

<button id="o2b-scan" style="
width:100%;padding:13px;margin-top:12px;
background:#7952b3;color:white;border:0;
border-radius:8px;font-weight:bold">
🔎 SCAN CHAPTER LIST
</button>
</div>
</details>

<details style="margin:12px 0">
<summary style="font-size:16px;font-weight:bold;padding:8px">
📚 Download settings
</summary>
<div style="padding:10px;background:#292929;border-radius:9px">
<select id="o2b-download" style="width:100%;padding:12px">
<option value="finished">Finished EPUB only</option>
<option value="unfinished">Unfinished EPUB only</option>
<option value="both" selected>Both in separate EPUBs</option>
<option value="combined">All chapters in one EPUB</option>
</select>
</div>
</details>

<div id="o2b-count" style="
padding:12px;background:#292929;border-radius:9px;
margin:12px 0;line-height:1.6">
No chapters scanned yet.
</div>

<div id="o2b-status" style="
padding:13px;background:#292929;
border-radius:9px;white-space:pre-line;
line-height:1.5;margin:12px 0">
Ready.
</div>

<div style="height:12px;background:#444;
border-radius:20px;overflow:hidden">
<div id="o2b-bar" style="
height:100%;width:0%;background:#4caf50;
transition:width .2s"></div>
</div>

<button id="o2b-start" style="
width:100%;padding:17px;margin-top:14px;
background:#168b45;color:white;border:0;
border-radius:10px;font-size:17px;font-weight:bold">
▶ START / RESUME SCRAPING
</button>

<button id="o2b-stop" style="
width:100%;padding:17px;margin-top:9px;
background:#f0a02b;color:#171717;border:0;
border-radius:10px;font-size:17px;font-weight:bold">
⏸ PAUSE SCRAPING
</button>

<button id="o2b-build" style="
width:100%;padding:17px;margin-top:9px;
background:#1976d2;color:white;border:0;
border-radius:10px;font-size:17px;font-weight:bold">
📦 BUILD & DOWNLOAD EPUBS
</button>

<button id="o2b-close" style="
width:100%;padding:14px;margin-top:9px;
background:#555;color:white;border:0;
border-radius:10px;font-size:15px">
✕ CLOSE PANEL
</button>

<div style="font-size:12px;color:#bbb;
margin-top:14px;line-height:1.6">
Keep this tab open during scraping. Your progress
is saved in Chrome on this device. Android may
suspend background tabs, so return periodically.
</div>
`;

document.body.appendChild(panel);

const status=t=>$("o2b-status").textContent=t;

function esc(s){
 return String(s).replace(/[&<>"']/g,c=>({
 "&":"&amp;","<":"&lt;",">":"&gt;",
 '"':"&quot;","'":"&#39;"
 }[c]));
}

function filename(s){
 return String(s).replace(/[\\/:*?"<>|]/g,"")
 .replace(/\s+/g," ").trim().slice(0,100)||"Omni Export";
}

/* DATABASE */

function openDB(){
 return new Promise((resolve,reject)=>{
  const q=indexedDB.open(DB,1);
  q.onupgradeneeded=()=>{
   if(!q.result.objectStoreNames.contains("records"))
    q.result.createObjectStore("records",{keyPath:"id"});
  };
  q.onsuccess=()=>{db=q.result;resolve()};
  q.onerror=()=>reject(q.error);
 });
}

function save(r){
 return new Promise((resolve,reject)=>{
  const tx=db.transaction("records","readwrite");
  tx.objectStore("records").put(r);
  tx.oncomplete=resolve;
  tx.onerror=()=>reject(tx.error);
 });
}

function getAll(){
 return new Promise((resolve,reject)=>{
  const q=db.transaction("records").objectStore("records").getAll();
  q.onsuccess=()=>resolve(q.result);
  q.onerror=()=>reject(q.error);
 });
}

async function getRecords(){
 return (await getAll())
 .filter(r=>r.threadId===threadId)
 .sort((a,b)=>a.recordNumber-b.recordNumber);
}

/* IDENTIFY NOVEL */

async function initialise(){

 const match=location.pathname.match(
  /\/translation\/([a-f0-9-]+)/i
 );

 if(!match)
  throw Error("Open an individual OmniTranslate chapter page first.");

 firstId=match[1];

 const response=await fetch(API+firstId,{
  credentials:"include"
 });

 if(!response.ok)throw Error("Could not access chapter.");

 const data=await response.json();

 threadId=data.threadId;

 if(!threadId)throw Error("Could not identify novel.");

 novelTitle=(
  document.querySelector('meta[property="og:title"]')?.content||
  document.title||
  "Omni Export"
 ).replace(/\s*[-|]\s*(OmniTranslate|ReadOmni).*$/i,"").trim();

 $("o2b-novel").innerHTML=
 "<b>Novel:</b> "+esc(novelTitle)+
 "<br><b>Thread:</b> "+threadId+
 "<br><b>Starting record:</b> "+firstId;

 await refreshCount();
}

async function refreshCount(){
 records=await getRecords();

 const scraped=records.filter(r=>r.text).length;

 $("o2b-count").innerHTML=
 "<b>Records scanned:</b> "+records.length+
 "<br><b>Chapters with saved text:</b> "+scraped+
 "<br><b>Finished:</b> "+records.filter(r=>r.status==="finished").length+
 "<br><b>Unfinished:</b> "+records.filter(r=>r.status!=="finished").length;
}

/* SETTINGS */

$("o2b-mode").onchange=()=>{
 mode=$("o2b-mode").value;
 $("o2b-range").style.display=mode==="range"?"block":"none";
 $("o2b-individual").style.display=mode==="individual"?"block":"none";
};

$("o2b-filter").onchange=()=>{
 filter=$("o2b-filter").value;
 renderList();
};

$("o2b-batch").onchange=()=>{
 batchSize=Number($("o2b-batch").value);
};

$("o2b-download").onchange=()=>{
 downloadMode=$("o2b-download").value;
};

/* SCAN CHAPTER LIST */

$("o2b-scan").onclick=async()=>{

 if(scanning||running)return;

 scanning=true;
 $("o2b-scan").disabled=true;

 try{

  const existing=await getRecords();
  const byId=new Map(existing.map(r=>[r.id,r]));

  let id=firstId;
  let number=1;
  let found=0;

  while(id){

   const saved=byId.get(id);

   if(saved){
    id=saved.nextId;
    number++;
    continue;
   }

   const response=await fetch(API+id,{
    credentials:"include"
   });

   if(!response.ok)throw Error("API error "+response.status);

   const data=await response.json();

   if(data.threadId!==threadId)
    throw Error("Different novel detected. Scan stopped.");

   const record={
    id,
    threadId,
    recordNumber:number,
    title:data.title||"",
    status:data.status||"unknown",
    text:"",
    nextId:data.next?.id||null
   };

   await save(record);
   byId.set(id,record);

   found++;

   status(
    "Scanning chapter list...\n"+
    "Records discovered: "+number+
    "\nNew records: "+found
   );

   id=record.nextId;
   number++;

   await new Promise(r=>setTimeout(r,250));
  }

  await refreshCount();
  renderList();

  status("Scan complete. "+records.length+
   " chapter records discovered.\nChoose your chapters and start scraping.");

 }catch(e){
  status("Scan stopped: "+e.message);
 }finally{
  scanning=false;
  $("o2b-scan").disabled=false;
 }
};

/* CHAPTER CHECKBOXES */

function matchesFilter(r){
 if(filter==="finished")return r.status==="finished";
 if(filter==="unfinished")return r.status!=="finished";
 return true;
}

function renderList(){

 if(mode!=="individual")return;

 const list=$("o2b-list");

 const visible=records.filter(matchesFilter);

 list.innerHTML=visible.map(r=>`
 <label style="
 display:flex;gap:9px;align-items:flex-start;
 padding:9px;border-bottom:1px solid #444">
 <input type="checkbox" data-id="${esc(r.id)}"
 ${selected.has(r.id)?"checked":""}
 style="width:19px;height:19px;flex-shrink:0">
 <span>
 <b>${r.recordNumber}. ${esc(r.title||"Untitled")}</b>
 <br><small style="color:#aaa">${esc(r.status)}</small>
 </span>
 </label>
 `).join("")||"No chapters match this filter.";

 list.querySelectorAll("input").forEach(box=>{
  box.onchange=()=>{
   if(box.checked)selected.add(box.dataset.id);
   else selected.delete(box.dataset.id);
  };
 });
}

$("o2b-selectall").onclick=()=>{
 records.filter(matchesFilter).forEach(r=>selected.add(r.id));
 renderList();
};

$("o2b-clear").onclick=()=>{
 selected.clear();
 renderList();
};

/* CHAPTER SELECTION */

function getSelection(){

 let list=records.filter(matchesFilter);

 if(mode==="range"){
  const from=Number($("o2b-from").value);
  const to=Number($("o2b-to").value);

  if(!Number.isInteger(from)||!Number.isInteger(to)||from<1||to<from)
   throw Error("Enter a valid chapter range.");

  rangeStart=from;
  rangeEnd=to;

  list=list.filter(r=>
   r.recordNumber>=from&&r.recordNumber<=to
  );
 }

 if(mode==="individual"){
  list=list.filter(r=>selected.has(r.id));
 }

 return list;
}

/* SCRAPE CHAPTER TEXT */

$("o2b-start").onclick=async()=>{

 if(running||scanning)return;

 try{

  const chosen=getSelection();

  if(!chosen.length)
   throw Error("No chapters selected.");

  running=true;
  paused=false;
  $("o2b-start").disabled=true;

  const total=chosen.length;
  let completed=0;

  const todo=chosen.filter(r=>!r.text);

  status(
   "Starting scraping...\n"+
   "Selected chapters: "+total+
   "\nAlready saved: "+(total-todo.length)+
   "\nRemaining: "+todo.length
  );

  for(let i=0;i<todo.length;i+=batchSize){

   if(paused)break;

   const batch=todo.slice(i,i+batchSize);

   for(const record of batch){

    if(paused)break;

    const response=await fetch(API+record.id,{
     credentials:"include"
    });

    if(!response.ok)
     throw Error("API returned "+response.status);

    const data=await response.json();

    if(data.threadId!==threadId)
     throw Error("Different novel detected.");

    const chunks=data.state?.chunks||{};

    const translated=Object.keys(chunks)
     .sort((a,b)=>Number(a)-Number(b))
     .map(k=>chunks[k].translation||"")
     .filter(Boolean)
     .join("\n\n");

    record.text=translated;
    record.status=data.status||record.status;
    record.title=data.title||record.title;

    await save(record);

    completed++;

    const percent=Math.round(
     (completed+(total-todo.length))/total*100
    );

    $("o2b-bar").style.width=percent+"%";

    status(
     "Scraping chapters...\n"+
     "Completed this session: "+completed+
     "\nRemaining: "+(todo.length-completed)+
     "\nCurrent: "+record.recordNumber+
     "\n"+(record.title||"Untitled")+
     "\nBatch size: "+batchSize
    );

    await new Promise(r=>setTimeout(r,300));
   }

   await refreshCount();

   if(paused)break;

   status(
    "Batch saved successfully.\n"+
    "Completed this session: "+completed+
    "\nStarting next batch..."
   );
  }

  await refreshCount();

  if(paused){
   status("Paused safely. Saved chapters are retained. Press START / RESUME to continue.");
  }else{
   status("Scraping complete!\nSelected chapters: "+total+
    "\nNewly scraped: "+completed+
    "\nAlready saved: "+(total-todo.length));
  }

 }catch(e){
  status("Scraping stopped: "+e.message);
 }finally{
  running=false;
  $("o2b-start").disabled=false;
 }
};

$("o2b-stop").onclick=()=>{
 if(!running){
  status("No scraping is running.");
  return;
 }
 paused=true;
 status("Pausing after the current chapter...");
};

/* EPUB LIBRARY */

async function loadZip(){

 if(window.JSZip)return;

 await new Promise((resolve,reject)=>{
  const s=document.createElement("script");
  s.src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js";
  s.onload=resolve;
  s.onerror=()=>reject(Error("Could not load ZIP library."));
  document.head.appendChild(s);
 });
}

async function makeEPUB(list,title){

 const zip=new JSZip();

 zip.file("mimetype","application/epub+zip",{
  compression:"STORE"
 });

 zip.file("META-INF/container.xml",
 '<?xml version="1.0"?>'+
 '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'+
 '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'+
 '</container>');

 let manifest="",spine="",nav="";

 list.forEach((r,i)=>{

  const fn="chapter-"+String(i+1).padStart(5,"0")+".xhtml";
  const chapterTitle=r.title||"Record "+r.recordNumber;

  const body=(r.text||"[No translated text available.]")
   .split(/\n\s*\n/)
   .map(p=>"<p>"+esc(p).replace(/\n/g,"<br/>")+"</p>")
   .join("");

  zip.file("OEBPS/"+fn,
   '<?xml version="1.0" encoding="UTF-8"?>'+
   '<html xmlns="http://www.w3.org/1999/xhtml">'+
   '<head><title>'+esc(chapterTitle)+'</title></head>'+
   '<body><h1>'+esc(chapterTitle)+'</h1>'+body+'</body></html>'
  );

  manifest+='<item id="c'+i+'" href="'+fn+'" media-type="application/xhtml+xml"/>';
  spine+='<itemref idref="c'+i+'"/>';
  nav+='<li><a href="'+fn+'">'+esc(chapterTitle)+'</a></li>';
 });

 zip.file("OEBPS/nav.xhtml",
 '<?xml version="1.0"?>'+
 '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">'+
 '<head><title>Contents</title></head>'+
 '<body><nav epub:type="toc"><h1>Contents</h1><ol>'+nav+'</ol></nav></body></html>');

 zip.file("OEBPS/content.opf",
 '<?xml version="1.0"?>'+
 '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">'+
 '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'+
 '<dc:identifier id="uid">omni-'+threadId+'-'+title+'</dc:identifier>'+
 '<dc:title>'+esc(novelTitle+" - "+title)+'</dc:title>'+
 '<dc:language>en</dc:language></metadata>'+
 '<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'+
 manifest+'</manifest><spine>'+spine+'</spine></package>');

 return zip.generateAsync({
  type:"uint8array",
  compression:"DEFLATE"
 });
}

/* BUILD DOWNLOAD */

$("o2b-build").onclick=async()=>{

 if(running||scanning){
  alert("Wait until scraping or scanning has stopped.");
  return;
 }

 if(!confirm(
  "Create EPUB files for "+novelTitle+"?\n\n"+
  "This will download a ZIP file.\n"+
  "Your saved records will not be deleted."
 ))return;

 try{

  await loadZip();

  let chosen=getSelection();
  chosen=chosen.filter(r=>r.text);

  if(!chosen.length)
   throw Error("No scraped chapters in the current selection.");

  const finished=chosen.filter(r=>r.status==="finished");
  const unfinished=chosen.filter(r=>r.status!=="finished");

  const zip=new JSZip();
  const name=filename(novelTitle);

  if(downloadMode==="finished"){
   if(!finished.length)throw Error("No finished chapters were scraped.");
   zip.file(name+" - Finished.epub",await makeEPUB(finished,"Finished"));
  }

  if(downloadMode==="unfinished"){
   if(!unfinished.length)throw Error("No unfinished chapters were scraped.");
   zip.file(name+" - Unfinished.epub",await makeEPUB(unfinished,"Unfinished"));
  }

  if(downloadMode==="both"){

   if(finished.length)
    zip.file("Finished/"+name+" - Finished.epub",
     await makeEPUB(finished,"Finished"));

   if(unfinished.length)
    zip.file("Unfinished/"+name+" - Unfinished.epub",
     await makeEPUB(unfinished,"Unfinished"));
  }

  if(downloadMode==="combined"){
   zip.file(name+" - Complete.epub",
    await makeEPUB(chosen,"Complete"));
  }

  zip.file("Manifest/chapters.json",JSON.stringify({
   novel:novelTitle,
   threadId,
   exportedRecords:chosen.length,
   finished:finished.length,
   unfinished:unfinished.length,
   chapters:chosen.map(r=>({
    id:r.id,
    recordNumber:r.recordNumber,
    title:r.title,
    status:r.status,
    translatedCharacters:r.text.length
   }))
  },null,2));

  status("Preparing ZIP download...");

  const blob=await zip.generateAsync({type:"blob"});
  const url=URL.createObjectURL(blob);
  const a=document.createElement("a");

  a.href=url;
  a.download=name+" - Omni Export.zip";

  document.body.appendChild(a);
  a.click();
  a.remove();

  setTimeout(()=>URL.revokeObjectURL(url),60000);

  status(
   "DOWNLOAD READY!\n"+
   "Exported chapters: "+chosen.length+
   "\nFinished: "+finished.length+
   "\nUnfinished: "+unfinished.length+
   "\nCheck Chrome Downloads."
  );

 }catch(e){
  status("Download failed: "+e.message);
 }
};

/* CLOSE */

$("o2b-close").onclick=()=>{
 if(running||scanning){
  alert("Pause scraping or wait for scanning to finish first.");
  return;
 }
 panel.remove();
};

/* STARTUP */

try{
 await openDB();
 await initialise();
}catch(e){
 status("Setup error: "+e.message);
}

})();
