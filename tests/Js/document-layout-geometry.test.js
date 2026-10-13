import test from 'node:test';
import assert from 'node:assert/strict';
import {clampPlacement,pagePoint} from '../../resources/js/document-layout-geometry.js';

test('display coordinates map to identical PDF placement across desktop and mobile scales',()=>{
    const page={width:595,height:842};
    for(const width of [900,600,300,240]) {
        const rect={left:20,top:50,width,height:width*842/595};
        const point=pagePoint(20+width*.4,50+rect.height*.6,rect,page);
        assert.ok(Math.abs(point.x-238)<.00001);assert.ok(Math.abs(point.y-505.2)<.00001);
    }
});
test('drag and resize never persist a box beyond any page edge',()=>{
    const page={width:595,height:842};
    const moved=clampPlacement({x_position:700,y_position:-30,width:180,height:26},page);
    assert.equal(moved.x_position,415);assert.equal(moved.y_position,0);
    const oversized=clampPlacement({x_position:30,y_position:30,width:900,height:1000},page);
    assert.equal(oversized.width,595);assert.equal(oversized.height,842);assert.equal(oversized.x_position,0);assert.equal(oversized.y_position,0);
});
test('normalized placement retains page number and typography settings',()=>{
    const saved=clampPlacement({page_number:2,x_position:72.5,y_position:40,width:100,height:30,font_size:14,alignment:'right',text_behavior:'wrap'},{width:595,height:842});
    assert.deepEqual(saved,{page_number:2,x_position:72.5,y_position:40,width:100,height:30,font_size:14,alignment:'right',text_behavior:'wrap'});
});
