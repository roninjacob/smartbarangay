import {clampPlacement, pagePoint} from './document-layout-geometry';

const root = document.querySelector('[data-document-designer]');
if (root) initialize(root);
async function initialize(root) {
    const find = (name) => root.querySelector(`[data-designer-${name}]`);
    const data = JSON.parse(find('data').textContent);
    const fields = new Map(data.fields.map(field => [field.key,field]));
    const positions = data.placements.map(position => ({...position,uid:crypto.randomUUID()}));
    const sheet=find('sheet'), canvas=find('canvas'), overlay=find('overlay'), status=find('status');
    const save=find('save'), previous=find('previous'), next=find('next'), settings=find('settings');
    let pdf, pageNumber=1, pageSize, selected=null, chosen=null, busy=false, dirty=false, rendering=false, pending=false, failed=false;
    function message(text,error=false) { status.textContent=text; status.classList.toggle('is-error',error); }
    function changed() { dirty=true; message('Unsaved layout changes. Click Save Layout when you are ready.'); }
    function selection() { return positions.find(position=>position.uid===selected); }
    function select(position) {
        selected=position?.uid ?? null; chosen=null;
        root.querySelectorAll('[data-designer-field]').forEach(button=>{button.classList.remove('is-selected');button.setAttribute('aria-pressed','false');});
        settings.disabled=!position;
        find('selected').textContent=position ? fields.get(position.key).label : 'Select a placed field';
        if(position) { find('font').value=position.font_size; find('align').value=position.alignment; find('behavior').value=position.text_behavior; }
        draw();
    }
    function sample(field) {
        const examples={resident_name:'Juan Dela Cruz',resident_address:'Barangay Calayo, Nasugbu, Batangas',resident_contact:'0912 345 6789',date_issued:'October 13, 2026',barangay_name:'Barangay Calayo'};
        return examples[field.name] ?? (field.type==='dropdown' ? field.options?.[0] ?? 'Sample option' : field.type==='number' ? '5' : field.type==='date' ? 'October 13, 2026' : 'Employment');
    }
    function draw() {
        if(!pageSize) return;
        const focused=document.activeElement?.dataset?.placement;
        overlay.replaceChildren(); find('placed').replaceChildren();
        const scale=sheet.clientWidth/pageSize.width;
        positions.filter(position=>Number(position.page_number)===pageNumber).forEach(position=>{
            const field=fields.get(position.key); if(!field) return;
            const box=document.createElement('div'); box.className='designer-box'; box.classList.toggle('is-selected',position.uid===selected);
            box.dataset.placement=position.uid; box.dataset.behavior=position.text_behavior;
            box.tabIndex=0; box.setAttribute('role','button'); box.setAttribute('aria-label',`Move ${field.label}`); box.setAttribute('aria-pressed',String(position.uid===selected));
            Object.assign(box.style,{left:`${Number(position.x_position)/pageSize.width*100}%`,top:`${Number(position.y_position)/pageSize.height*100}%`,width:`${Number(position.width)/pageSize.width*100}%`,height:`${Number(position.height)/pageSize.height*100}%`,textAlign:position.alignment});
            const text=document.createElement('span'); text.className='designer-box-text'; text.textContent=find('sample').checked ? sample(field) : field.label;
            let font=Number(position.font_size)*scale;
            if(position.text_behavior==='auto_resize') {
                const measurement=document.createElement('canvas').getContext('2d'); measurement.font=`${font}px Arial`;
                const available=Math.max(1,Number(position.width)*scale-4);
                font=Math.min(font,font*available/Math.max(1,measurement.measureText(text.textContent).width),Number(position.height)*scale/1.2);
            }
            text.style.fontSize=`${Math.max(1,font)}px`; text.style.fontFamily='Arial, sans-serif'; box.append(text);
            const handle=document.createElement('span'); handle.className='designer-resize'; handle.dataset.resize='true'; handle.setAttribute('aria-hidden','true'); box.append(handle);
            overlay.append(box);
            const button=document.createElement('button'); button.type='button'; button.className='btn btn-outline-secondary'; button.textContent=field.label; button.addEventListener('click',()=>select(position)); find('placed').append(button);
        });
        if(focused) overlay.querySelector(`[data-placement="${focused}"]`)?.focus({preventScroll:true});
        if(!find('placed').childElementCount) { const note=document.createElement('p'); note.className='app-note'; note.textContent='No fields placed on this page.'; find('placed').append(note); }
    }
    function add(key,x,y) {
        if(!pageSize||busy||rendering||failed||!fields.has(key)||positions.length>=300) return;
        const position=clampPlacement({uid:crypto.randomUUID(),key,page_number:pageNumber,x_position:x,y_position:y,width:Math.min(180,pageSize.width),height:26,font_size:12,alignment:'left',text_behavior:'auto_resize'},pageSize);
        positions.push(position); select(position); changed();
    }
    root.querySelectorAll('[data-designer-field]').forEach(button=>{
        button.setAttribute('aria-pressed','false');
        button.addEventListener('click',()=>{
            if(busy||rendering||failed||!pageSize) return;
            select(null); chosen=button.dataset.designerField; button.classList.add('is-selected'); button.setAttribute('aria-pressed','true');
            message(`${button.textContent.trim()} selected. Tap the document to place it, or focus the document and press Enter.`);
            if(matchMedia('(max-width:1199px)').matches) sheet.scrollIntoView({behavior:'smooth',block:'center'});
        });
        button.addEventListener('dragstart',event=>{event.dataTransfer.setData('text/plain',button.dataset.designerField);event.dataTransfer.effectAllowed='copy';});
    });
    overlay.addEventListener('dragover',event=>{event.preventDefault();event.dataTransfer.dropEffect='copy';});
    overlay.addEventListener('drop',event=>{event.preventDefault();if(!pageSize)return;const point=pagePoint(event.clientX,event.clientY,overlay.getBoundingClientRect(),pageSize);add(event.dataTransfer.getData('text/plain'),point.x,point.y);});
    let gesture=null;
    overlay.addEventListener('pointerdown',event=>{
        if(busy||rendering||failed||!pageSize||event.button!==0) return;
        const target=event.target.closest('[data-placement]');
        const point=pagePoint(event.clientX,event.clientY,overlay.getBoundingClientRect(),pageSize);
        if(!target) { if(chosen) add(chosen,point.x,point.y); else select(null); return; }
        const position=positions.find(item=>item.uid===target.dataset.placement);
        const resize=!!event.target.closest('[data-resize]');
        select(position);
        gesture={pointer:event.pointerId,position,start:{...position},point,resize};
        overlay.setPointerCapture(event.pointerId); event.preventDefault();
    });
    overlay.addEventListener('pointermove',event=>{
        if(busy||!gesture||gesture.pointer!==event.pointerId) return;
        const point=pagePoint(event.clientX,event.clientY,overlay.getBoundingClientRect(),pageSize);
        const dx=point.x-gesture.point.x,dy=point.y-gesture.point.y;
        let update={...gesture.start};
        if(gesture.resize) {update.width=Math.max(12,Math.min(Number(update.width)+dx,pageSize.width-Number(update.x_position)));update.height=Math.max(12,Math.min(Number(update.height)+dy,pageSize.height-Number(update.y_position)));}
        else {update.x_position=Number(update.x_position)+dx;update.y_position=Number(update.y_position)+dy;}
        Object.assign(gesture.position,clampPlacement(update,pageSize)); draw(); changed();
    });
    function finish(event) { if(gesture?.pointer!==event.pointerId)return;gesture=null;if(overlay.hasPointerCapture(event.pointerId))overlay.releasePointerCapture(event.pointerId); }
    overlay.addEventListener('pointerup',finish); overlay.addEventListener('pointercancel',finish);
    function adjust(direction,resize=false) {
        const position=selection(); if(!position||busy||rendering)return;
        const step=5;
        if(resize) {
            if(['narrower','wider'].includes(direction)) position.width=Math.max(12,Math.min(Number(position.width)+(direction==='wider'?step:-step),pageSize.width-Number(position.x_position)));
            else position.height=Math.max(12,Math.min(Number(position.height)+(direction==='taller'?step:-step),pageSize.height-Number(position.y_position)));
        } else {position.x_position=Number(position.x_position)+(direction==='left'?-step:direction==='right'?step:0);position.y_position=Number(position.y_position)+(direction==='up'?-step:direction==='down'?step:0);}
        Object.assign(position,clampPlacement(position,pageSize));draw();changed();
    }
    root.querySelectorAll('[data-designer-move]').forEach(button=>button.addEventListener('click',()=>adjust(button.dataset.designerMove)));
    root.querySelectorAll('[data-designer-size]').forEach(button=>button.addEventListener('click',()=>adjust(button.dataset.designerSize,true)));
    overlay.addEventListener('keydown',event=>{
        if(event.key==='Enter'&&chosen){event.preventDefault();add(chosen,pageSize.width/3,pageSize.height/3);return;}
        const direction={ArrowLeft:'left',ArrowRight:'right',ArrowUp:'up',ArrowDown:'down'}[event.key];
        if(direction&&selection()){event.preventDefault();adjust(direction);}
        else if(event.key==='Enter'&&event.target.dataset.placement){event.preventDefault();select(positions.find(item=>item.uid===event.target.dataset.placement));}
    });
    for(const name of ['font','align','behavior'])find(name).addEventListener('change',()=>{
        const position=selection();if(!position||busy)return;
        const font=Number(find('font').value); if(!Number.isFinite(font)||font<4||font>72){message('Choose a font size between 4 and 72.',true);find('font').value=position.font_size;return;}
        Object.assign(position,{font_size:font,alignment:find('align').value,text_behavior:find('behavior').value});draw();changed();
    });
    find('remove').addEventListener('click',()=>{if(busy)return;const index=positions.findIndex(item=>item.uid===selected);if(index<0)return;positions.splice(index,1);select(null);changed();});
    find('sample').addEventListener('change',draw);
    function fail() {failed=true;save.disabled=true;sheet.hidden=true;previous.disabled=next.disabled=true;message('The official PDF could not be loaded. Reload this page or review the document configuration.',true);}
    async function render() {
        if(rendering) {pending=true;return;}
        rendering=true;save.disabled=true;previous.disabled=next.disabled=true;
        try {
            const page=await pdf.getPage(pageNumber),base=page.getViewport({scale:1});
            pageSize={width:base.width,height:base.height};
            let adjusted=false;
            positions.filter(position=>Number(position.page_number)===pageNumber).forEach(position=>{
                const bounded=clampPlacement(position,pageSize);
                if(['x_position','y_position','width','height'].some(key=>Math.abs(Number(position[key])-bounded[key])>0.001)) {
                    Object.assign(position,bounded);adjusted=true;
                }
            });
            if(adjusted) {dirty=true;message('Some placements were adjusted to fit this page. Review them before saving.');}

            const width=Math.min(900,Math.max(1,root.querySelector('.designer-paper-area').clientWidth-24));
            const scale=width/base.width,ratio=Math.min(devicePixelRatio||1,2),viewport=page.getViewport({scale:scale*ratio});
            sheet.style.width=`${width}px`;canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);
            await page.render({canvasContext:canvas.getContext('2d'),viewport}).promise;
            sheet.hidden=false;find('page').textContent=`Page ${pageNumber} of ${pdf.numPages}`;canvas.setAttribute('aria-label',`Official document, page ${pageNumber} of ${pdf.numPages}`);draw();
        } catch { fail(); }
        finally {rendering=false;save.disabled=busy||failed;previous.disabled=failed||pageNumber<=1;next.disabled=failed||pageNumber>=pdf.numPages;if(pending){pending=false;render();}}
    }
    previous.addEventListener('click',()=>{if(rendering||busy||pageNumber<=1)return;pageNumber--;select(null);render();});
    next.addEventListener('click',()=>{if(rendering||busy||pageNumber>=pdf.numPages)return;pageNumber++;select(null);render();});
    save.addEventListener('click',async()=>{
        if(busy||rendering||failed)return;
        busy=true;save.disabled=true;settings.disabled=true;message('Saving layout…');
        const payload={version:data.version,pdf_revision:data.pdf_revision,placements:positions.map(position=>{
            const field=fields.get(position.key);const result={field_id:field.field_id,system_name:field.system_name};
            for(const key of ['page_number','x_position','y_position','width','height','font_size']) result[key]=Math.round(Number(position[key])*1000)/1000;
            result.alignment=position.alignment;result.text_behavior=position.text_behavior;return result;
        })};
        try {
            const response=await fetch(data.save_url,{method:'PUT',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(payload)});
            const result=await response.json();
            if(!response.ok) throw new Error(Object.values(result.errors??{}).flat().join(' ')||'Unable to save. Reload the page and sign in again if needed.');
            data.version=result.version;dirty=false;message('Document layout saved. The original PDF is unchanged.');
        } catch(error) {message(error.message||'Unable to save. Your unsaved layout is still on this page.',true);}
        finally {busy=false;save.disabled=failed;settings.disabled=!selection();}
    });
    window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
    try {
        const [pdfjs,worker]=await Promise.all([import('pdfjs-dist'),import('pdfjs-dist/build/pdf.worker.min.mjs?url')]);pdfjs.GlobalWorkerOptions.workerSrc=worker.default;
        const url=new URL(data.pdf_url,location.href);if(url.origin!==location.origin)throw new Error('Invalid preview origin');
        pdf=await pdfjs.getDocument({url:url.href,isEvalSupported:false,enableXfa:false,useSystemFonts:true}).promise;
        if(positions.some(position=>Number(position.page_number)>pdf.numPages)) message('Some saved fields refer to pages that are unavailable. Review the layout before saving.',true);
        else message('Choose a field from the toolbox to begin.');
        await render();
        let lastWidth=0;
        new ResizeObserver(entries=>{const width=Math.round(entries[0].contentRect.width);if(width!==lastWidth){lastWidth=width;render();}}).observe(root.querySelector('.designer-paper-area'));
    } catch {fail();}
}
