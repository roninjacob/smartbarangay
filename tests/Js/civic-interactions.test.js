import test from 'node:test';
import assert from 'node:assert/strict';
import { filePresentation } from '../../resources/js/civic-interaction-helpers.js';

test('only matching raster MIME/extensions get previews; active/oversize content never does', () => {
    for (const [name,type,size,expected] of [['id.png','image/png',500,true],['id.svg','image/svg+xml',500,false],['id.png','image/svg+xml',500,false],['id.html','image/png',500,false],['id.webp','image/webp',500,true],['id.pdf','application/pdf',500,false],['id.jpg','image/jpeg',6*1024*1024,false]]) {
        assert.equal(filePresentation({name,type,size}).image, expected, name);
    }
    assert.equal(filePresentation({name:'proof.pdf',type:'application/pdf',size:2*1024*1024}).size,'2.0 MB');
});

test('filename text remains data and is not turned into markup', () => {
    assert.equal(filePresentation({name:'<img src=x>.pdf',type:'application/pdf',size:20}).name,'<img src=x>.pdf');
});
