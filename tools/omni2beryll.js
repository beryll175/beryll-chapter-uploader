(async()=>{
const THREAD="019d4afb-5b8a-759d-8d4e-94c4a0ebf8bb";
const FIRST="019d4afb-5e3c-726b-ab4e-2202493a3a54";
const API="https://api.readomni.com/translation/";

if(document.getElementById("o2b-panel"))return;

const panel=document.createElement("div");
panel.id="o2b-panel";
panel.style="position:fixed;z-index:2147483647;top:8px;left:8px;right:8px;max-height:90vh;overflow:auto;background:#171717;color:white;padding:14px;border:2px solid #f90;border-radius:12px;font:14px sans-serif";
panel.innerHTML=`
<b>Omni → Beryll Exporter</b>
<div id="o2b-status" style="margin:10px 0">Ready. Keep this tab open while exporting.</div>
<button id="o2b-start">Start / Resume</button>
<button id="o2b-stop">Pause</button>
<button id="o2b-build">Build EPUB ZIP</button>
<button id="o2b-close">Close</button>
<div style="margin-top:8px;font-size:12px">Data is saved in this browser on this device. Do not clear site data until export is complete.</div>`;
document.body.appendChild(panel);

const $=id=>document.getElementById(id);
const status=t=>$("o2b-status").textContent=t;
let stop=false,db;

const openDB=()=>new Promise((ok,no)=>{
let q=indexedDB.open("omni2beryll-v2",1);
q.onupgradeneeded=()=>q.result.createObjectStore("records",{keyPath:"id"});
q.onsuccess=()=>{db=q.result;ok()};
q.onerror=()=>no(q.error);
});

const save=r=>new Promise((ok,no)=>{
let t=db.transaction("records","readwrite");
t.objectStore("records").put(r);
t.oncomplete=ok;
t.onerror=()=>no(t.error);
});

const all=()=>new Promise((ok,no)=>{
let q=db.transaction("records").objectStore("records").getAll();
q.onsuccess=()=>ok(q.result.sort((a,b)=>a.recordNumber-b.recordNumber));
q.onerror=()=>no(q.error);
});

await openDB();

$("o2b-start").onclick=async()=>{
stop=false;
let rows=await all();
let last=rows[rows.length-1];
let id=last?.nextId||FIRST;
let n=last?last.recordNumber+1:1;

try{
while(id&&!stop){
let res=await fetch(API+id,{credentials:"include"});
if(!res.ok)throw Error("API returned "+res.status);

let d=await res.json();

if(d.threadId!==THREAD)
throw Error("Record belongs to a different thread. Stopped for safety.");

let chunks=d.state?.chunks||{};
let translated=Object.keys(chunks)
.sort((a,b)=>Number(a)-Number(b))
.map(k=>chunks[k].translation||"")
.filter(Boolean)
.join("\n\n");

let rec={
id,
recordNumber:n,
title:d.title||"",
status:d.status||"unknown",
text:translated,
nextId:d.next?.id||null
};

await save(rec);

status("Saved record "+n+" · "+rec.status+" · "+(rec.title||"Untitled"));

id=rec.nextId;
n++;

await new Promise(r=>setTimeout(r,300));
}

status(stop?"Paused. Press Start / Resume to continue.":"Reached end of chapter chain.");
}catch(e){
status("Stopped: "+e.message);
}
};

$("o2b-stop").onclick=()=>{
stop=true;
status("Pausing after current request…");
};

$("o2b-close").onclick=()=>panel.remove();

$("o2b-build").onclick=async()=>{
try{
if(!window.JSZip){
await new Promise((ok,no)=>{
let s=document.createElement("script");
s.src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js";
s.onload=ok;
s.onerror=no;
document.head.appendChild(s);
});
}

let rows=await all();
if(!rows.length)throw Error("No records saved yet.");

const esc=s=>String(s).replace(/[&<>"]/g,c=>({
"&":"&amp;",
"<":"&lt;",
">":"&gt;",
'"':"&quot;"
}[c]));

const makeEpub=async(list,name)=>{
let z=new JSZip();

z.file("mimetype","application/epub+zip",{compression:"STORE"});

z.file("META-INF/container.xml",
'<?xml version="1.0"?>'+
'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'+
'<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'+
'</container>');

let manifest="",spine="",nav="";

list.forEach((r,i)=>{
let fn="ch"+String(i+1).padStart(4,"0")+".xhtml";
let title=r.title||("Unfinished record "+r.recordNumber);

let body=(r.text||"[No translated text saved for this record.]")
.split(/\n\s*\n/)
.map(p=>"<p>"+esc(p).replace(/\n/g,"<br/>")+"</p>")
.join("");

z.file("OEBPS/"+fn,
'<?xml version="1.0" encoding="UTF-8"?>'+
'<html xmlns="http://www.w3.org/1999/xhtml">'+
'<head><title>'+esc(title)+'</title></head>'+
'<body><h1>'+esc(title)+'</h1>'+body+'</body></html>');

manifest+='<item id="c'+i+'" href="'+fn+'" media-type="application/xhtml+xml"/>';
spine+='<itemref idref="c'+i+'"/>';
nav+='<li><a href="'+fn+'">'+esc(title)+'</a></li>';
});

z.file("OEBPS/nav.xhtml",
'<?xml version="1.0"?>'+
'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">'+
'<head><title>Contents</title></head>'+
'<body><nav epub:type="toc"><h1>Contents</h1><ol>'+nav+'</ol></nav></body></html>');

z.file("OEBPS/content.opf",
'<?xml version="1.0"?>'+
'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid">'+
'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'+
'<dc:identifier id="uid">omni2beryll-'+name+'</dc:identifier>'+
'<dc:title>Winner in Life [Quick Transmigration] - '+name+'</dc:title>'+
'<dc:language>en</dc:language></metadata>'+
'<manifest>'+
'<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'+
manifest+
'</manifest><spine>'+spine+'</spine></package>');

return z.generateAsync({type:"uint8array",compression:"DEFLATE"});
};

let done=rows.filter(r=>r.status==="finished"&&r.text);
let pending=rows.filter(r=>!(r.status==="finished"&&r.text));

let outer=new JSZip();

if(done.length){
outer.file("Finished/Winner in Life - Finished.epub",
await makeEpub(done,"Finished"));
}

if(pending.length){
outer.file("Unfinished/Winner in Life - Unfinished.epub",
await makeEpub(pending,"Unfinished"));
}

outer.file("Manifest/chapters.json",
JSON.stringify(rows.map(({id,recordNumber,title,status,text,nextId})=>({
id,
recordNumber,
title,
status,
translatedCharacters:text.length,
nextId
})),null,2));

let blob=await outer.generateAsync({type:"blob"});
let a=document.createElement("a");
a.href=URL.createObjectURL(blob);
a.download="Winner-in-Life-Omni-Export.zip";
a.click();

setTimeout(()=>URL.revokeObjectURL(a.href),60000);

status("Export ready: "+rows.length+" records. Check Chrome Downloads.");

}catch(e){
status("Build failed: "+e.message);
}
};

})();
