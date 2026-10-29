// Exercises the production renderer without calling any AI service or browser session.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../quiz.php'), 'utf8');
const start = source.indexOf('        function renderQuestion()');
const end = source.indexOf('        function goTo(index)', start);
const renderer = source.slice(start, end);
const nodes = {};
const context = {
    quizData: {questions:[],total:1}, currentQ:0, answers:{}, submitted:false,
    document:{getElementById:id=>nodes[id] ??= {style:{},innerHTML:'',textContent:''},querySelectorAll:()=>[]},
    escHtml:s=>String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;'),
    renderBreakdown:()=>'',
};
vm.createContext(context); vm.runInContext(renderer, context);
let checks=0;
function render(question, answer, submitted=false) {
    context.quizData.questions=[{question:'Local test question',marks:10,...question}];
    context.answers=answer===undefined?{}:{1:answer}; context.submitted=submitted;
    context.renderQuestion(); return nodes.questionArea.innerHTML;
}
for (const correct of ['True','False','true','false']) {
    let html=render({type:'truefalse',correct,options:null});
    assert.equal((html.match(/data-option=/g)||[]).length,2);
    assert.match(html,/data-option="True"/); assert.match(html,/data-option="False"/);
    context.selectOption('False'); assert.equal(context.answers[1],'False');
    assert.match(nodes.questionArea.innerHTML,/aria-pressed="true"/);
    html=render({type:'truefalse',correct,options:null},correct,true);
    assert.equal((html.match(/ correct locked/g)||[]).length,1);
    assert.equal((html.match(/ disabled/g)||[]).length,2); checks+=5;
}
let html=render({type:'truefalse',correct:'B',options:null},'B');
assert.match(html,/data-option="A"/); assert.match(html,/data-option="B" aria-pressed="true"/); checks+=2;
html=render({type:'mcq',correct:'C',options:{A:'One',B:'Two',C:'Three',D:'Four'}},'C',true);
assert.equal((html.match(/data-option=/g)||[]).length,4); assert.match(html,/correct locked/); checks+=2;
html=render({type:'short',correct:'Model',options:null},'My answer');
assert.match(html,/<textarea/); assert.match(html,/My answer/); checks+=2;
console.log(`PASS: ${checks} Quiz renderer checks (word/letter True-False, selection, locked grading, MCQ, short answer). No AI calls.`);
