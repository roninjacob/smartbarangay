import test from 'node:test';
import assert from 'node:assert/strict';
import { filePresentation, landingMotion } from '../../resources/js/civic-interaction-helpers.js';

test('only matching raster MIME/extensions get previews; active/oversize content never does', () => {
    for (const [name,type,size,expected] of [['id.png','image/png',500,true],['id.svg','image/svg+xml',500,false],['id.png','image/svg+xml',500,false],['id.html','image/png',500,false],['id.webp','image/webp',500,true],['id.pdf','application/pdf',500,false],['id.jpg','image/jpeg',6*1024*1024,false]]) {
        assert.equal(filePresentation({name,type,size}).image, expected, name);
    }
    assert.equal(filePresentation({name:'proof.pdf',type:'application/pdf',size:2*1024*1024}).size,'2.0 MB');
});

test('filename text remains data and is not turned into markup', () => {
    assert.equal(filePresentation({name:'<img src=x>.pdf',type:'application/pdf',size:20}).name,'<img src=x>.pdf');
});

function motionFixture(reduced = false) {
    const handlers = {};
    const attributes = {};
    const hero = {dataset:{}};
    const control = {hidden:true,setAttribute:(key,value) => {attributes[key]=value;},addEventListener:(event,callback) => {handlers.click=callback;}};
    const preference = {matches:reduced,addEventListener:(event,callback) => {handlers.preference=callback;}};
    const doc = {hidden:false,addEventListener:(event,callback) => {handlers.visibility=callback;}};
    landingMotion(hero,control,preference,doc);
    return {hero,control,preference,doc,handlers,attributes};
}

test('landing motion can be paused/resumed and suspends in a hidden tab', () => {
    const f=motionFixture();
    assert.equal(f.hero.dataset.motionEnabled,'true');
    f.handlers.click();
    assert.equal(f.hero.dataset.motionPaused,'true');
    assert.equal(f.control.textContent,'Resume page animation');
    assert.equal(f.attributes['aria-pressed'],'true');
    f.doc.hidden=true; f.handlers.visibility(); f.handlers.click();
    assert.equal(f.hero.dataset.motionPaused,'true');
    f.doc.hidden=false; f.handlers.visibility();
    assert.equal(f.hero.dataset.motionPaused,'false');
});

test('reduced motion is honored initially and when the OS preference changes', () => {
    const f=motionFixture(true);
    assert.equal(f.hero.dataset.motionEnabled,'false');
    assert.equal(f.control.hidden,true);
    f.preference.matches=false; f.handlers.preference();
    assert.equal(f.hero.dataset.motionEnabled,'true');
    assert.equal(f.control.hidden,false);
    f.handlers.click();
    f.preference.matches=true; f.handlers.preference();
    assert.equal(f.hero.dataset.motionPaused,'true');
    f.preference.matches=false; f.handlers.preference();
    assert.equal(f.hero.dataset.motionPaused,'true');
});
