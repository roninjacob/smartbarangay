import {fittedFont} from './preview-text-fit';
const root=document.querySelector('[data-filled-preview]');
if(root) initialize(root);
async function initialize(root){
    const find=name=>root.querySelector(`[data-filled-${name}]`),data=JSON.parse(find('data').textContent);
    let pdf,pageNumber=1,busy=false,pending=false,failed=false;
    const sheet=find('sheet'),canvas=find('canvas'),overlay=find('overlay'),status=find('status'),previous=find('previous'),next=find('next');
    function fail(){failed=true;sheet.hidden=true;previous.disabled=next.disabled=true;status.textContent='The historical preview could not be loaded. Return to the reservation details or reload this page.';status.setAttribute('role','alert');}
    async function render(){
        if(busy){pending=true;return;}busy=true;root.setAttribute('aria-busy','true');status.textContent='Loading document page...';previous.disabled=next.disabled=true;
        try{
            const page=await pdf.getPage(pageNumber),base=page.getViewport({scale:1});
            const width=Math.min(900,Math.max(1,root.querySelector('.filled-paper-area').clientWidth-24)),scale=width/base.width;
            const ratio=Math.min(devicePixelRatio||1,2),viewport=page.getViewport({scale:scale*ratio});
            sheet.style.width=`${width}px`;canvas.width=Math.ceil(viewport.width);canvas.height=Math.ceil(viewport.height);
            await page.render({canvasContext:canvas.getContext('2d'),viewport}).promise;sheet.hidden=false;overlay.replaceChildren();
            const measure=(text,size)=>{const context=canvas.getContext('2d');context.font=`${size}px Arial`;return context.measureText(text).width;};
            let warnings=false;const occupied=[];
            for(const field of data.placements.filter(field=>Number(field.page_number)===pageNumber)){
                const x=Number(field.x_position),y=Number(field.y_position),w=Number(field.width),h=Number(field.height);
                if(x<0||y<0||w<=0||h<=0||x+w>base.width+.01||y+h>base.height+.01){warnings=true;continue;}
                if(occupied.some(box=>x<box.x+box.w&&x+w>box.x&&y<box.y+box.h&&y+h>box.y)){warnings=true;continue;}
                occupied.push({x,y,w,h});
                const element=document.createElement('span');element.className='filled-text';element.setAttribute('aria-label',field.label);
                const text=field.text_behavior==='auto_resize'?field.text.replace(/\s+/g,' '):field.text;
                element.textContent=text;
                const font=fittedFont(text,w*scale,h*scale,Number(field.font_size)*scale,field.text_behavior,measure);
                Object.assign(element.style,{left:`${x/base.width*100}%`,top:`${y/base.height*100}%`,width:`${w/base.width*100}%`,height:`${h/base.height*100}%`,fontSize:`${font}px`,textAlign:field.alignment,whiteSpace:field.text_behavior==='auto_resize'?'nowrap':'pre-wrap'});
                overlay.append(element);
                let actualFont=font;
                for(let attempt=0;attempt<100&&(element.scrollHeight>element.clientHeight+1||element.scrollWidth>element.clientWidth+1);attempt++) {
                    actualFont*=0.95;element.style.fontSize=`${actualFont}px`;
                }
            }
            canvas.dataset.renderedWidth=String(Math.round(width));sheet.hidden=false;find('page').textContent=`Page ${pageNumber} of ${pdf.numPages}`;canvas.setAttribute('aria-label',`Temporary filled document preview, page ${pageNumber} of ${pdf.numPages}`);
            status.textContent=warnings?'Some saved placements overlap or are outside this page and could not be displayed. Recorded values are available below.':'Temporary preview. The original PDF remains unchanged.';
        }catch{fail();}finally{busy=false;root.setAttribute('aria-busy','false');previous.disabled=failed||pageNumber<=1;next.disabled=failed||pageNumber>=pdf.numPages;if(pending&&!failed){pending=false;render();}}
    }
    previous.addEventListener('click',()=>{if(!busy&&pageNumber>1){pageNumber--;render();}});next.addEventListener('click',()=>{if(!busy&&pageNumber<pdf.numPages){pageNumber++;render();}});
    try{
        const [pdfjs,worker]=await Promise.all([import('pdfjs-dist'),import('pdfjs-dist/build/pdf.worker.min.mjs?url')]);pdfjs.GlobalWorkerOptions.workerSrc=worker.default;
        const url=new URL(data.pdf_url,location.href);if(url.origin!==location.origin)throw new Error('Invalid origin');
        pdf=await pdfjs.getDocument({url:url.href,isEvalSupported:false,enableXfa:false,useSystemFonts:true}).promise;
        if(data.placements.some(field=>Number(field.page_number)>pdf.numPages))throw new Error('Missing historical page');
        await render();let lastWidth=0;new ResizeObserver(entries=>{const width=Math.round(entries[0].contentRect.width);if(width!==lastWidth&&!failed){lastWidth=width;render();}}).observe(root.querySelector('.filled-paper-area'));
    }catch{fail();}
}
