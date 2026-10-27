import test from 'node:test';
import assert from 'node:assert/strict';
import { chartSeries, chartMotion } from '../../resources/js/report-chart-motion.js';

test('exploration preserves exact source labels including decimal currency and zero buckets', () => {
    const labels = ['Oct 1 · Amount (PHP): ₱0.00 · Payments: 0', 'Oct 2 · Amount (PHP): ₱99,999,999.99 · Payments: 1'];
    const rows = chartSeries('55,215 645,45', labels);
    assert.equal(rows[0].label,labels[0]);
    assert.equal(rows[1].label,labels[1]);
    assert.equal(rows[1].x,645);
});

test('malformed or mismatched geometry disables exploration instead of inventing values', () => {
    for(const points of ['', 'NaN,3', '1,Infinity', '1,', ',2', '1,2,3', '1,2 3,4']) {
        assert.deepEqual(chartSeries(points,['one']),[],points);
    }
    assert.equal(chartSeries('55,215',['single bucket'])[0].label,'single bucket');
});

function fixture(length = 100) {
    const animations = [];
    const handlers = {};
    const preference = {matches:false,addEventListener:(name,callback)=>{handlers.preference=callback;}};
    const doc = {hidden:false,addEventListener:(name,callback)=>{handlers.visibility=callback;}};
    const animate = (frames,options) => {const entry={frames,options,cancelled:false,cancel(){this.cancelled=true;}};animations.push(entry);return entry;};
    const line = {points:'unchanged',getTotalLength:()=>length,animate};
    const bars = [{style:{width:'33.333%'},animate},{style:{width:'100%'},animate}];
    const motion=chartMotion(line,bars,preference,doc);
    return {animations,handlers,preference,doc,line,bars,motion};
}

test('replay cancels earlier effects and never changes geometry or original bar widths', () => {
    const f=fixture();f.motion.play();
    assert.equal(f.animations.length,3);
    assert.equal(f.animations[0].frames[0].strokeDashoffset,100);
    assert.equal(f.animations[1].options.fill,'backwards');
    f.motion.play();
    assert.equal(f.animations.length,6);
    assert.equal(f.animations.slice(0,3).every(a=>a.cancelled),true);
    assert.equal(f.line.points,'unchanged');
    assert.equal(f.bars[0].style.width,'33.333%');
});

test('hidden tabs and reduced motion reveal final values by cancelling effects', () => {
    const f=fixture(); f.preference.matches=true; f.motion.play();
    assert.equal(f.animations.length,0);
    f.preference.matches=false; f.motion.play();
    f.doc.hidden=true; f.handlers.visibility();
    assert.equal(f.animations.every(a=>a.cancelled),true);
    f.motion.play(); assert.equal(f.animations.length,3);
    f.doc.hidden=false; f.motion.play();
    f.preference.matches=true; f.handlers.preference();
    assert.equal(f.animations.every(a=>a.cancelled),true);
});

test('zero-length and unavailable line geometry remain static while valid bars can animate', () => {
    const f=fixture(0); f.motion.play(); assert.equal(f.animations.length,2);
    f.line.getTotalLength=()=>{throw new Error('Geometry unavailable');};
    assert.doesNotThrow(()=>f.motion.play());
});
