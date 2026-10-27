/**
 * The console's PDF buttons: a respondent's results (the page's exportPDF()),
 * one organization's profile (exportOrgPDF()) and the full research report
 * (exportFullPDF()).
 *
 * The drawing code below is the page's own — copied out of
 * page/Human_Edge_Scorecard.html by line, not retyped — with three changes
 * only: each export takes its records as an argument instead of reading the
 * page's state (lastRecord, getRecords(), includeSynthetic). DIMS, RUNGS and
 * BAND_EDGES come with the records from the server, read from the published
 * instrument, so a PDF from the console is the PDF the page draws.
 *
 *   [data-record-pdf] → /research-data/{id}/scorecard-data  → exportPDF(record)
 *   [data-org-pdf]    → /analytics/organisation/report-data → exportOrgPDF(name, records)
 *   [data-report-pdf] → /report/data                        → exportFullPDF(records, includeSynthetic)
 *
 * The click is delegated because the buttons usually live inside dialogs whose
 * markup is injected. jsPDF is fetched only when a button is pressed; its URL
 * rides on the button as data-jspdf. Loaded once, from admin/_shell-footer.php.
 */
(function () {
'use strict';
let DIMS = [], RUNGS = [], BAND_EDGES = [];

/* ---- the page's functions, verbatim apart from the three signatures ---- */
function band(score){
  if(score==null||isNaN(score)) return null;
  let i=0; while(i<BAND_EDGES.length && score>=BAND_EDGES[i]) i++;
  return RUNGS[i];
}
function orgCodes(recs){
  const first={}; recs.forEach(r=>{ const b=(r.org?.org||'').trim(); if(!b) return; const t=new Date(r.timestamp).getTime(); if(!(b in first) || t<first[b]) first[b]=t; });
  const map={}; Object.keys(first).sort((a,b)=>first[a]-first[b]).forEach((b,i)=>{ map[b]='O'+String(i+1).padStart(3,'0'); });
  return r=>map[(r.org?.org||'').trim()]||'';
}
const mean = a => a.length? a.reduce((x,y)=>x+y,0)/a.length : null;
const sd   = a => { if(a.length<2) return 0; const m=mean(a); return Math.sqrt(a.reduce((x,y)=>x+(y-m)**2,0)/a.length); };
const fmt  = v => v==null?'—':v.toFixed(2);
function toast(msg){ const t=document.getElementById('toast'); t.textContent=msg; t.classList.add('show'); clearTimeout(t._h); t._h=setTimeout(()=>t.classList.remove('show'),2600); }
function fmtDate(iso){ return new Date(iso).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'}); }
function summarize(dims){
  const scored=DIMS.map(d=>dims[d.code]).filter(v=>v!=null);
  if(!scored.length) return {mean:null,level:null};
  const overall=+mean(scored).toFixed(2);
  return {mean:overall, level:band(overall)};
}
function aggregate(recs){
  const out={};
  for(const d of DIMS){
    const vals=recs.map(r=>r.dims?.[d.code]).filter(v=>v!=null);
    out[d.code]= vals.length? {n:vals.length, mean:+mean(vals).toFixed(2), min:Math.min(...vals), max:Math.max(...vals), sd:+sd(vals).toFixed(2)} : {n:0,mean:null,min:null,max:null,sd:null};
  }
  return out;
}
function aggDims(agg){ const d={}; for(const c in agg) d[c]=agg[c].mean; return d; }
const f2=v=>v==null||!isFinite(v)?'—':v.toFixed(2);
const pct=p=>p==null||!isFinite(p)?'—':Math.round(p*100)+'%';
const nums=a=>a.filter(x=>typeof x==='number'&&isFinite(x));
function quantile(a,p){ const s=nums(a).sort((x,y)=>x-y); if(!s.length) return null; const h=(s.length-1)*p, lo=Math.floor(h), hi=Math.ceil(h); return s[lo]+(s[hi]-s[lo])*(h-lo); }
function levelShares(a){ const v=nums(a); const counts=RUNGS.map(r=>v.filter(x=>band(x)===r).length); return {n:v.length,counts,shares:counts.map(k=>v.length?k/v.length:0)}; }
function pdfDoc(){ const J=window.jspdf?.jsPDF; if(!J){ toast('PDF library not loaded'); return null; } return new J({unit:'mm',format:'a4'}); }
function pdfHeader(doc,title,sub){
  const W=doc.internal.pageSize.getWidth();
  doc.setFillColor(6,13,24); doc.rect(0,0,W,26,'F');
  doc.setTextColor(232,240,250); doc.setFont('times','normal'); doc.setFontSize(15); doc.text('The Human Edge Scorecard',14,12);
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(160,180,202); doc.text(title,14,19);
  doc.setFontSize(8); doc.text(sub,W-14,19,{align:'right'});
  return 36;
}
function pdfBars(doc,y,dims,_unused,agg){
  const W=doc.internal.pageSize.getWidth(); const x0=14, lw=62, bx=x0+lw, bw=W-bx-14-22;
  doc.setFontSize(9);
  for(const d of DIMS){
    const v=dims[d.code]; const b=band(v);
    doc.setTextColor(40,40,40); doc.setFont('helvetica','normal'); doc.text(d.name,x0,y+3.5);
    doc.setFillColor(235,238,243); doc.rect(bx,y,bw,4.5,'F');
    if(v!=null){ const hex=b.hex.replace('#',''); doc.setFillColor(parseInt(hex.slice(0,2),16),parseInt(hex.slice(2,4),16),parseInt(hex.slice(4,6),16)); doc.rect(bx,y,bw*(v-1)/4,4.5,'F');
      if(agg&&agg[d.code]&&agg[d.code].n>1){ const a=agg[d.code]; doc.setDrawColor(60,60,60); doc.setLineWidth(.4); doc.line(bx+bw*(a.min-1)/4,y-1,bx+bw*(a.min-1)/4,y+5.5); doc.line(bx+bw*(a.max-1)/4,y-1,bx+bw*(a.max-1)/4,y+5.5); } }
    doc.setTextColor(40,40,40); doc.setFont('helvetica','normal'); doc.text(v==null?'—':v.toFixed(2),W-14,y+3.5,{align:'right'});
    doc.setFontSize(7); doc.setTextColor(120,120,120); doc.text(b?b.label:'UNSCORED',W-14-10,y+3.5,{align:'right'}); doc.setFontSize(9);
    y+=9;
  }
  return y;
}
// ── Respondent PDF: the results screen, drawn for paper from the record ──────
function hexRgb(h){ h=h.replace('#',''); return [parseInt(h.slice(0,2),16),parseInt(h.slice(2,4),16),parseInt(h.slice(4,6),16)]; }
function pdfOpacity(doc,a){ try{ doc.setGState(new doc.GState({opacity:a,'stroke-opacity':a})); }catch(e){} }
function pdfArc(doc,cx,cy,r,a0,a1,rgb,w,alpha){
  const n=Math.max(6,Math.ceil((a1-a0)/3)); const pts=[];
  for(let i=0;i<=n;i++){ const a=(a0+(a1-a0)*i/n-90)*Math.PI/180; pts.push([cx+r*Math.cos(a),cy+r*Math.sin(a)]); }
  doc.setDrawColor(...rgb); doc.setLineWidth(w); doc.setLineCap('round'); pdfOpacity(doc,alpha);
  for(let i=1;i<pts.length;i++) doc.line(pts[i-1][0],pts[i-1][1],pts[i][0],pts[i][1]);
  pdfOpacity(doc,1);
}
function pdfGauge(doc,cx,cy,score){
  const r=17,w=4,A0=-135,SW=270,edges=[1,...BAND_EDGES,5],b=band(score);
  for(let i=0;i<5;i++){ const a0=A0+(edges[i]-1)/4*SW,a1=A0+(edges[i+1]-1)/4*SW; pdfArc(doc,cx,cy,r,a0,a1,hexRgb(RUNGS[i].hex),w,b===RUNGS[i]?1:.35);
    const am=((a0+a1)/2-90)*Math.PI/180, lx=cx+(r+w/2+3.5)*Math.cos(am), ly=cy+(r+w/2+3.5)*Math.sin(am); const anchor=lx<cx-2?'right':lx>cx+2?'left':'center';
    doc.setFont('helvetica',b===RUNGS[i]?'bold':'normal'); doc.setFontSize(6.5); doc.setTextColor(...(b===RUNGS[i]?hexRgb(RUNGS[i].hex):[120,120,120])); doc.text(RUNGS[i].label,lx,ly+1,{align:anchor}); }
  if(score!=null){ const a=(A0+(Math.min(5,Math.max(1,score))-1)/4*SW-90)*Math.PI/180; doc.setFillColor(255,255,255); doc.setDrawColor(6,13,24); doc.setLineWidth(.5); doc.circle(cx+r*Math.cos(a),cy+r*Math.sin(a),1.8,'FD'); }
  doc.setFont('times','normal'); doc.setFontSize(16); doc.setTextColor(6,13,24); doc.text(score==null?'\u2014':score.toFixed(1),cx,cy+1.5,{align:'center'});
  doc.setFont('helvetica','bold'); doc.setFontSize(7); if(b){ doc.setTextColor(...hexRgb(b.hex)); doc.text(b.label,cx,cy+6,{align:'center'}); }
}
function pdfCountRing(doc,cx,cy,n,total){
  const r=17,w=4,gap=8;
  for(let i=0;i<total;i++){ const a0=-90+i*(360/total)+gap/2,a1=-90+(i+1)*(360/total)-gap/2; pdfArc(doc,cx,cy,r,a0,a1,i<n?[61,130,196]:[214,220,228],w,1); }
  doc.setFont('times','normal'); doc.setFontSize(16); doc.setTextColor(6,13,24); doc.text(n+'/'+total,cx,cy+2,{align:'center'});
}
function pdfRadar(doc,cx,cy,R,dims){
  const N=DIMS.length, ang=i=>(-90+i*360/N)*Math.PI/180;
  doc.setLineWidth(.25); doc.setDrawColor(200,208,220);
  for(let k=1;k<=5;k++){ const rr=R*k/5; for(let i=0;i<N;i++){ const a=ang(i),b2=ang((i+1)%N); doc.line(cx+rr*Math.cos(a),cy+rr*Math.sin(a),cx+rr*Math.cos(b2),cy+rr*Math.sin(b2)); } }
  for(let i=0;i<N;i++){ const a=ang(i); doc.line(cx,cy,cx+R*Math.cos(a),cy+R*Math.sin(a)); }
  doc.setFont('helvetica','normal'); doc.setFontSize(6); doc.setTextColor(150,150,150); for(let k=1;k<=5;k++) doc.text(String(k),cx+1.2,cy-R*k/5+0.8);
  doc.setFontSize(7.5); doc.setTextColor(60,60,60); DIMS.forEach((d,i)=>{ const a=ang(i); const lx=cx+(R+5)*Math.cos(a), ly=cy+(R+5)*Math.sin(a); doc.text(d.code,lx,ly+1,{align:Math.abs(Math.cos(a))<.2?'center':Math.cos(a)<0?'right':'left'}); });
  const pts=DIMS.map((d,i)=>{ const v=dims[d.code]; if(v==null) return null; const a=ang(i); return [cx+R*(v/5)*Math.cos(a),cy+R*(v/5)*Math.sin(a)]; }).filter(Boolean);
  if(pts.length>2){ const segs=[]; for(let i=1;i<pts.length;i++) segs.push([pts[i][0]-pts[i-1][0],pts[i][1]-pts[i-1][1]]);
    doc.setDrawColor(232,147,63); doc.setFillColor(232,147,63); doc.setLineWidth(.6); pdfOpacity(doc,.18); doc.lines(segs,pts[0][0],pts[0][1],[1,1],'F',true); pdfOpacity(doc,1); doc.lines(segs,pts[0][0],pts[0][1],[1,1],'S',true);
    pts.forEach(p=>doc.circle(p[0],p[1],.9,'F')); }
}
// What to do next: the action attached to each of the questions the respondent rated lowest.
// Up to four, lowest rating first, and no more than two from any one dimension, so at least two dimensions appear.
function nextStepPicks(rec){
  const items=[];
  DIMS.forEach((d,di)=>{ const R=(rec.responses&&rec.responses[d.code])||{}; d.qs.forEach((q,qi)=>{ const v=R[qi]; if(v!=null) items.push({d,q,v,di,qi}); }); });
  items.sort((a,b)=>a.v-b.v||a.di-b.di||a.qi-b.qi);
  const per={}, pick=[];
  for(const it of items){ if(pick.length>=4) break; if((per[it.d.code]||0)>=2) continue; per[it.d.code]=(per[it.d.code]||0)+1; pick.push(it); }
  return pick;
}
function nextStepLead(pick){
  return pick.length&&Math.min(...pick.map(p=>p.v))>=4
    ? 'You rated yourself highly across the board. These suggestions come from the questions on which you rated yourself lowest.'
    : 'These suggestions come from the questions you rated lowest.';
}
function nextSteps(rec){
  const pick=nextStepPicks(rec); if(!pick.length) return '';
  return `<p class="muted" style="margin-bottom:14px">${nextStepLead(pick)}</p><div class="next-list">${pick.map(p=>`<div class="next-item"><div class="next-k">${esc(p.d.name)} · ${esc(p.q.t)}</div><p>${esc(p.q.next)}</p></div>`).join('')}</div>`;
}
function exportPDF(rec){
  if(!rec) return; const doc=pdfDoc(); if(!doc) return; const W=doc.internal.pageSize.getWidth();
  let y=pdfHeader(doc,'Results',fmtDate(rec.timestamp)+' \u00b7 '+rec.year);
  const lv=rec.level?RUNGS.find(r=>r.name===rec.level):null; const lvRgb=lv?hexRgb(lv.hex):[110,110,110];
  const unscored=DIMS.filter(d=>rec.dims[d.code]==null);
  // who
  doc.setTextColor(6,13,24); doc.setFont('times','normal'); doc.setFontSize(20); doc.text([rec.org.org,rec.org.name||rec.userName||'Respondent'].filter(Boolean).join(' \u00b7 '),14,y); y+=7;
  doc.setFont('helvetica','normal'); doc.setFontSize(9); doc.setTextColor(110,110,110); doc.text([rec.org.role,rec.org.tenure,rec.org.sector,rec.org.size].filter(Boolean).join(' \u00b7 '),14,y); y+=4.5;
  const contact=[rec.userEmail||rec.org.email,rec.userPhone||rec.org.phone].filter(Boolean).join(' \u00b7 '); if(contact){ doc.setTextColor(140,140,140); doc.text(contact,14,y); y+=4.5; }
  y+=6;
  // headline
  doc.setFont('times','normal'); doc.setFontSize(15); doc.setTextColor(6,13,24);
  const head=doc.splitTextToSize('By your own assessment, your Human Edge level is '+(lv?lv.name:'unscored')+'.',W-28); doc.text(head,14,y); y+=head.length*7+4;
  // score block (left) and radar (right)
  const top=y;
  doc.setFont('times','normal'); doc.setFontSize(44); doc.setTextColor(...lvRgb); doc.text(rec.mean!=null?rec.mean.toFixed(1):'\u2014',14,top+16);
  doc.setFont('helvetica','bold'); doc.setFontSize(11); if(lv){ doc.setTextColor(...lvRgb); doc.text(lv.name,14,top+24); }
  doc.setFont('helvetica','normal'); doc.setFontSize(8.5); doc.setTextColor(110,110,110); const cap=doc.splitTextToSize('Your overall score out of 5, from the average of all your answers.',80); doc.text(cap,14,top+30);
  if(unscored.length){ doc.setFontSize(8); doc.setTextColor(150,150,150); const un=doc.splitTextToSize(unscored.map(d=>d.name).join(', ')+' unscored and left out of the average.',80); doc.text(un,14,top+30+cap.length*4+2); }
  pdfRadar(doc,W-14-34,top+30,26,rec.dims);
  y=top+66;
  // gauge and count ring
  doc.setFont('helvetica','normal'); doc.setFontSize(8.5); doc.setTextColor(90,90,90);
  const capFont=()=>{ doc.setFont('helvetica','normal'); doc.setFontSize(8.5); doc.setTextColor(90,90,90); };
  pdfGauge(doc,60,y+22,rec.mean); capFont(); doc.text('Where your score sits on the five levels',60,y+50,{align:'center'});
  pdfCountRing(doc,W-60,y+22,DIMS.length-unscored.length,DIMS.length); capFont(); doc.text('Dimensions scored',W-60,y+50,{align:'center'});
  y+=60;
  // six dimensions
  doc.setDrawColor(220,224,230); doc.setLineWidth(.3); doc.line(14,y,W-14,y); y+=7;
  doc.setFont('helvetica','bold'); doc.setFontSize(10); doc.setTextColor(6,13,24); doc.text('Six skills',14,y); doc.setFont('helvetica','normal'); doc.setFontSize(8); doc.setTextColor(120,120,120); doc.text('Each score is the average of that skill\u2019s three answers, out of 5.',W-14,y,{align:'right'}); y+=7;
  y=pdfBars(doc,y,rec.dims,null,null)+4;
  // footer
  const H=doc.internal.pageSize.getHeight();
  doc.setFontSize(7.5); doc.setTextColor(130,130,130);
  const foot=doc.splitTextToSize('Five levels: '+RUNGS.map(r=>r.v+' '+r.name+' ('+r.desc.replace(/\.$/,'').replace(/^./,c=>c.toLowerCase())+')').join(' \u00b7 ')+'. Self-assessment. \u00a9 2026 SHADOKA, LLC. All rights reserved.',W-28);
  doc.text(foot,14,H-8-(foot.length-1)*3.4);
  const picks=nextStepPicks(rec);
  if(picks.length){
    doc.addPage(); let y2=pdfHeader(doc,'What to do next',fmtDate(rec.timestamp));
    doc.setFont('helvetica','normal'); doc.setFontSize(10); doc.setTextColor(90,90,90);
    const lead=doc.splitTextToSize(nextStepLead(picks),W-28); doc.text(lead,14,y2); y2+=lead.length*5+6;
    picks.forEach(p=>{ doc.setFont('helvetica','bold'); doc.setFontSize(10.5); doc.setTextColor(6,13,24); doc.text(p.d.name+' \u00b7 '+p.q.t,14,y2); y2+=5.5;
      doc.setFont('helvetica','normal'); doc.setFontSize(10); doc.setTextColor(60,60,60); const t=doc.splitTextToSize(p.q.next,W-28); doc.text(t,14,y2); y2+=t.length*5+7; });
  }
  doc.save('Human_Edge_Scorecard_'+[rec.org.org,rec.org.name||rec.userName||'respondent'].filter(Boolean).join('_').replace(/[^\w]+/g,'_')+'.pdf');
}
async function exportOrgPDF(name, recs){
  if(!name) return;   // a blank organization name means none given, never an organization
  if(!recs.length) return;
  const agg=aggregate(recs); const dims=aggDims(agg); const s=summarize(dims);
  const doc=pdfDoc(); if(!doc) return; const W=doc.internal.pageSize.getWidth();
  let y=pdfHeader(doc,'Organization profile',recs.length+' scorecards');
  doc.setTextColor(6,13,24); doc.setFont('times','normal'); doc.setFontSize(22); doc.text(name,14,y); y+=7;
  doc.setFont('helvetica','normal'); doc.setFontSize(10); doc.setTextColor(60,60,60);
  doc.text('Level '+(s.level?s.level.v+' · '+s.level.name:'unscored')+'   ·   Overall score '+fmt(s.mean),14,y); y+=10;
  y=pdfBars(doc,y,dims,null,agg);
  doc.setFontSize(8); doc.setTextColor(130,130,130); doc.text('Self-assessment. Each respondent\u2019s level follows the average of all their answers. \u00b7 \u00a9 2026 SHADOKA, LLC. All rights reserved.',14,doc.internal.pageSize.getHeight()-12);
  doc.save('Human_Edge_Scorecard_'+name.replace(/[^\w]+/g,'_')+'.pdf');
}
async function exportFullPDF(recs, includeSynthetic){
  if(!recs.length){ toast('Nothing to export'); return; }
  const doc=pdfDoc(); if(!doc) return; const W=doc.internal.pageSize.getWidth(), H=doc.internal.pageSize.getHeight();
  const agg=aggregate(recs); const dims=aggDims(agg); const s=summarize(dims);
  let y=pdfHeader(doc,'Research report',new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}));
  doc.setTextColor(6,13,24); doc.setFont('times','normal'); doc.setFontSize(24); doc.text('Human Edge Scorecard dataset',14,y); y+=8;
  doc.setFont('helvetica','normal'); doc.setFontSize(10); doc.setTextColor(60,60,60);
  doc.text(recs.length+' scorecards · '+new Set(recs.map(r=>r.org?.org).filter(Boolean)).size+' organizations · mean overall score '+fmt(s.mean),14,y); y+=10;
  doc.setFontSize(9); doc.setTextColor(90,90,90);
  doc.text(doc.splitTextToSize('Level follows each respondent\u2019s overall score, the average of all their answers. Scores are self-reported and unverified; the sample is self-selected. '+(includeSynthetic?'This export INCLUDES synthetic demo records.':'Synthetic demo records are excluded.'),W-28),14,y); y+=12;
  doc.setFont('helvetica','bold'); doc.setFontSize(10); doc.setTextColor(6,13,24); doc.text('The index: share of respondents at each level',14,y); y+=6;
  const ic=[14,56,70,90,113,136,159,182]; doc.setFontSize(7.5); doc.setTextColor(120,120,120); ['Skill','n',...RUNGS.map(r=>r.name),'Median'].forEach((h,i)=>doc.text(h,ic[i],y)); y+=4;
  doc.setDrawColor(200,200,200); doc.line(14,y-2.5,W-14,y-2.5); doc.setFont('helvetica','normal'); doc.setFontSize(8.5); doc.setTextColor(40,40,40);
  [{name:'Overall',vals:recs.map(r=>r.mean)}].concat(DIMS.map(d=>({name:d.name,vals:recs.map(r=>r.dims?.[d.code])}))).forEach(row=>{ const sh=levelShares(row.vals); doc.text(row.name,ic[0],y); doc.text(String(sh.n),ic[1],y); sh.shares.forEach((p,i)=>doc.text(pct(p),ic[2+i],y)); doc.text(f2(quantile(row.vals,.5)),ic[7],y); y+=5; });
  y+=6;
  RUNGS.forEach(r=>{const n=recs.filter(x=>x.level===r.name).length; doc.setTextColor(60,60,60); doc.text(r.v+' · '+r.name,16,y); doc.text(String(n)+'  ('+Math.round(n/recs.length*100)+'%)',60,y); const hex=r.hex.replace('#',''); doc.setFillColor(parseInt(hex.slice(0,2),16),parseInt(hex.slice(2,4),16),parseInt(hex.slice(4,6),16)); doc.rect(85,y-3,Math.max(.5,(W-99)*n/recs.length),3.5,'F'); y+=6;});
  y+=6; doc.setFont('helvetica','bold'); doc.setFontSize(10); doc.setTextColor(6,13,24); doc.text('Records',14,y); y+=6;
  doc.setFontSize(7.5); doc.setFont('helvetica','bold'); doc.setTextColor(120,120,120);
  const cols=[14,74,110,134,156]; ['Organization','Sector','Year','Level','Overall'].forEach((h,i)=>doc.text(h,cols[i],y)); y+=4;
  doc.setDrawColor(200,200,200); doc.line(14,y-2.5,W-14,y-2.5); doc.setFont('helvetica','normal');
  const code=orgCodes(recs); const key=r=>code(r)||'\uffff';
  recs.slice().sort((a,b)=>key(a).localeCompare(key(b))).forEach(r=>{
    if(y>H-18){ doc.addPage(); y=pdfHeader(doc,'Research report · records','continued'); doc.setFontSize(7.5); }
    doc.setTextColor(40,40,40);
    doc.text(code(r)||'Not given',cols[0],y);
    doc.text(String(r.org?.sector||'').slice(0,16),cols[1],y);
    doc.text(String(r.year||''),cols[2],y);
    doc.text(String(r.level||'—'),cols[3],y);
    doc.text(fmt(r.mean),cols[4],y);
    y+=4.4;
  });
  const pages=doc.internal.getNumberOfPages();
  for(let i=1;i<=pages;i++){ doc.setPage(i); doc.setFontSize(7.5); doc.setTextColor(140,140,140); doc.text('Human Edge Scorecard · self-assessment · © 2026 SHADOKA, LLC · page '+i+' of '+pages,14,H-8); }
  doc.save('Human_Edge_Scorecard_Research_Report_'+new Date().toISOString().slice(0,10)+'.pdf');
}

/* ---- the buttons ---- */
let loading = null;
function library(source) {
  if (window.jspdf && window.jspdf.jsPDF) return Promise.resolve();
  if (loading) return loading;
  loading = new Promise(function (resolve, reject) {
    const script = document.createElement('script');
    script.src = source;
    script.onload = resolve;
    script.onerror = function () { loading = null; reject(new Error('PDF library not loaded')); };
    document.head.appendChild(script);
  });
  return loading;
}

document.addEventListener('click', async function (event) {
  if (event.defaultPrevented || event.button !== 0) return;
  const trigger = event.target.closest && event.target.closest('[data-record-pdf],[data-org-pdf],[data-report-pdf]');
  if (!trigger) return;
  event.preventDefault();

  const url = trigger.dataset.recordPdf || trigger.dataset.orgPdf || trigger.dataset.reportPdf;
  const idle = trigger.textContent;
  trigger.textContent = 'Building…';
  trigger.disabled = true;

  try {
    await library(trigger.dataset.jspdf);
    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const payload = await response.json().catch(function () { return null; });
    if (!response.ok || !payload || payload.success === false) throw new Error((payload && payload.message) || 'Nothing to export');

    const data = payload.data;
    DIMS = data.DIMS; RUNGS = data.RUNGS; BAND_EDGES = data.BAND_EDGES;

    if (trigger.dataset.recordPdf !== undefined) exportPDF(data.record);
    else if (trigger.dataset.orgPdf !== undefined) await exportOrgPDF(data.name, data.records);
    else await exportFullPDF(data.records, data.includeSynthetic);
  } catch (error) {
    toast(error.message);
  } finally {
    trigger.textContent = idle;
    trigger.disabled = false;
  }
});
})();
