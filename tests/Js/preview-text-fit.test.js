import test from 'node:test';
import assert from 'node:assert/strict';
import {fittedFont} from '../../resources/js/preview-text-fit.js';
const measure=(text,size)=>text.length*size*.6;
test('auto resize fits long text within both width and height',()=>{const text='A very long Resident name';const font=fittedFont(text,80,20,14,'auto_resize',measure);assert.ok(font<14);assert.ok(measure(text,font)<=80);assert.ok(font*1.2<=20);});
test('wrap keeps multiple lines inside the field area',()=>{const text='Long purpose for employment\n\nand supporting documents';const font=fittedFont(text,100,30,14,'wrap',measure);assert.ok(font<14);assert.ok(font>0);});
test('short text retains configured font size',()=>{assert.ok(Math.abs(fittedFont('Name',180,30,12,'auto_resize',measure)-12)<0.001);});
