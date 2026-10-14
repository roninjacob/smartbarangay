export function fittedFont(text, width, height, font, behavior, measure) {
    const fits = size => {
        if (behavior === 'auto_resize') return measure(text.replace(/\s+/g,' '),size)<=width && size*1.2<=height;
        let lines=1, line='';
        for(const [index,paragraph] of text.split('\n').entries()) {
            if(index>0) {lines++;line='';}
            for(const character of paragraph) {
                if(measure(line+character,size)>width && line!=='') {lines++;line='';}
                line+=character;
            }
        }
        return lines*size*1.2<=height;
    };
    let low=0.1, high=font;
    for(let i=0;i<20;i++){const middle=(low+high)/2;if(fits(middle)) low=middle;else high=middle;}
    return low;
}
