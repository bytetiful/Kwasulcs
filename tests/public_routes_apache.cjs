const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'lcs-route-apache-'));
let server;
let checks = 0;
function check(ok, label) { assert.ok(ok, label); checks++; console.log('PASS:', label); }
const pages = ['dashboard','courses','course','calendar','settings','notifications','profile','privacy-policy','chat','chat_ai','chat_dm','chat_group','assignment_tests','assignment_test','assignment_test_receipt','assignment_test_submissions','assignment_test_audit','online_quiz','online_quiz_edit','online_quiz_monitor','online_quiz_result','forgot_password'];
const retained = ['index','logout','admin','go','download','assignment_test_file','chat_download','online_quiz_take','online_quiz_api','assignment_test_submission','assignment_test_mark','assignment_test_mark_action','assignment_test_action','ai_chat','notify_cron','telegram_webhook','google_oauth_callback','push_subscribe'];
async function getPort() { return new Promise((resolve,reject)=>{const socket=net.createServer();socket.on('error',reject);socket.listen(0,'127.0.0.1',()=>{const result=socket.address().port;socket.close(()=>resolve(result));});}); }
(async () => {
  const listen = await getPort();
  const documentRoot = path.join(temporary, 'html');
  for (const scope of ['', 'LMS-portal']) {
    const dir = path.join(documentRoot,scope); fs.mkdirSync(path.join(dir,'assets'),{recursive:true});
    fs.copyFileSync(path.join(root,'.htaccess'),path.join(dir,'.htaccess'));
    const php = '<?php header("Content-Type: application/json"); echo json_encode(["script"=>basename(__FILE__),"method"=>$_SERVER["REQUEST_METHOD"],"query"=>$_SERVER["QUERY_STRING"]??"","body"=>file_get_contents("php://input"),"parameters"=>$_GET]);';
    for (const page of [...pages,...retained]) fs.writeFileSync(path.join(dir,page+'.php'),php);
    fs.writeFileSync(path.join(dir,'assets','fixture.css'),'body{color:green}');
    fs.writeFileSync(path.join(dir,'assets','fixture.js'),'window.assetLoaded=true;');
  }
  const config = `ServerRoot "/Applications/XAMPP/xamppfiles"
Listen 127.0.0.1:${listen}
ServerName localhost
PidFile "${temporary}/httpd.pid"
DefaultRuntimeDir "${temporary}"
ErrorLog "${temporary}/error.log"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule unixd_module modules/mod_unixd.so
LoadModule mime_module modules/mod_mime.so
LoadModule dir_module modules/mod_dir.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule php_module modules/libphp.so
TypesConfig /Applications/XAMPP/xamppfiles/etc/mime.types
AddType application/x-httpd-php .php
User ${os.userInfo().username}
Group staff
DocumentRoot "${documentRoot}"
<Directory "${documentRoot}">
Options FollowSymLinks
AllowOverride All
Require all granted
</Directory>
`;
  fs.writeFileSync(path.join(temporary,'httpd.conf'),config);
  server = spawn('/Applications/XAMPP/xamppfiles/bin/httpd',['-f',path.join(temporary,'httpd.conf'),'-DFOREGROUND'],{detached:true,stdio:['ignore','pipe','pipe']});
  let log=''; server.stderr.on('data', data=>log+=data.toString());
  const origin=`http://127.0.0.1:${listen}`;
  for(let i=0;i<50;i++){try{await fetch(origin+'/courses');break;}catch(_){if(server.exitCode!==null)throw new Error(log);await new Promise(r=>setTimeout(r,100));}}
  for (const prefix of ['/', '/LMS-portal/']) {
    for (const page of pages) {
      const response=await fetch(origin+prefix+page+'?id=12&view=day',{headers:{Accept:'text/html'},redirect:'manual'});
      check(response.status===200 && (await response.json()).script===page+'.php', `${prefix}${page} executes the unchanged PHP script`);
      const legacy=await fetch(origin+prefix+page+'.php?id=12&date=2026-10-13&encoded=a%2Fb',{headers:{Accept:'text/html'},redirect:'manual'});
      check(legacy.status===301 && legacy.headers.get('location')===origin+prefix+page+'?id=12&date=2026-10-13&encoded=a%2Fb', `${prefix}${page}.php preserves query in its canonical redirect`);
    }
    const followed=await fetch(origin+prefix+'course.php?id=12&view=day',{headers:{Accept:'text/html'}});
    const body=await followed.json();
    check(followed.url===origin+prefix+'course?id=12&view=day' && body.parameters.id==='12' && body.parameters.view==='day','canonical redirect has no loop and preserves GET values');
    for(const route of ['settings','notifications','profile']) for(const suffix of ['', '.php']) {
      const response=await fetch(origin+prefix+route+suffix,{method:'POST',body:'csrf=fixture&value=kept',redirect:'manual'});
      const data=await response.json();check(response.status===200 && data.method==='POST' && data.body==='csrf=fixture&value=kept','POST body and method survive clean and legacy screen routes');
    }
    for(const route of retained) {
      const response=await fetch(origin+prefix+route+'.php?id=12',{headers:{Accept:'text/html'},redirect:'manual'});
      check(response.status===200 && (await response.json()).script===route+'.php',`${route}.php is not redirected or cosmetically rewritten`);
    }
    const xhr=await fetch(origin+prefix+'courses.php',{headers:{'X-Requested-With':'XMLHttpRequest',Accept:'application/json'},redirect:'manual'});
    check(xhr.status===200,'legacy AJAX page request is not canonicalized');
    const nativeFetch=await fetch(origin+prefix+'courses.php',{headers:{'Sec-Fetch-Dest':'empty'},redirect:'manual'});
    check(nativeFetch.status===200,'browser fetch destination is not redirected');
    const head=await fetch(origin+prefix+'dashboard.php',{method:'HEAD',redirect:'manual'});
    check(head.status===301,'safe legacy HEAD is canonicalized');
    for(const asset of ['assets/fixture.css','assets/fixture.js']) check((await fetch(new URL(asset,origin+prefix+'courses'))).status===200,'relative assets resolve from a clean page within the app scope');
    check((await fetch(origin+prefix+'download',{redirect:'manual'})).status===404,'unapproved extensionless file alias is not exposed');
  }
  console.log(`Apache route assertions passed: ${checks}`);
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
  if(server){server.kill('SIGTERM');await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));}
  fs.rmSync(temporary,{recursive:true,force:true});
});
