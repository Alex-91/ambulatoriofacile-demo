const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/assets/js/login-push-notifications.js'), 'utf8');

function fixture({ standalone=true, permission='default', result='granted', unsupported=false, failure=false }={}) {
  const events = {};
  const panel = { hidden:true };
  const button = { disabled:false, addEventListener:(name,fn)=>events[name]=fn };
  const status = { textContent:'' };
  const storage = new Map();
  let requests=0;
  const notification = { permission, requestPermission:()=>{
    requests++;
    return failure ? Promise.reject(new Error('test')) : Promise.resolve(result);
  }};
  const context = {
    document:{getElementById:id=>({loginPushPanel:panel,loginEnablePush:button,loginPushStatus:status})[id]},
    window:{matchMedia:()=>({matches:standalone}), ...(unsupported ? {} : {Notification:notification,PushManager:{}})},
    navigator:{serviceWorker:{}}, Notification:notification,
    localStorage:{setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)}
  };
  vm.runInNewContext(source, context);
  return {panel,button,status,storage,events,requests:()=>requests};
}

(async()=>{
  let f=fixture({standalone:false});
  assert.equal(f.panel.hidden,true);
  assert.equal(f.events.click,undefined);
  f=fixture();
  assert.equal(f.panel.hidden,false);
  assert.equal(f.requests(),0,'No prompt on page load');
  const click=f.events.click();
  assert.equal(f.requests(),1,'Permission requested directly during tap');
  await click;
  assert.equal(f.storage.get('push_login_activation_pending'),'1');
  assert.match(f.status.textContent,/Ora accedi/);
  for (const permission of ['denied','default']) {
    f=fixture({result:permission}); await f.events.click();
    assert.equal(f.storage.has('push_login_activation_pending'),false);
    assert.equal(f.button.disabled,false);
    assert.match(f.status.textContent,permission==='denied' ? /impostazioni/ : /non concesso/);
  }
  f=fixture({permission:'granted'}); await f.events.click();
  assert.equal(f.requests(),0);
  assert.equal(f.storage.get('push_login_activation_pending'),'1');
  f=fixture({failure:true}); await f.events.click();
  assert.match(f.status.textContent,/Non è stato possibile/);
  assert.equal(f.button.disabled,false);
  f=fixture({unsupported:true});
  assert.equal(f.button.disabled,true);

  // Exercise the actual post-login registration script with a cached endpoint.
  const header=fs.readFileSync(path.join(root,'rest/app/Views/partials/header.php'),'utf8').replace(/\r\n/g,'\n');
  const start=header.indexOf("(function () {\n  'use strict';",header.indexOf('window.PUSH_MOBILE_CFG'));
  const script=header.slice(start,header.indexOf('</script>',start));
  assert.ok(start>0);
  for (const [pending,ok,expected] of [[true,true,1],[true,false,1],[false,true,0]]) {
    const values=new Map([['push_mobile_registered_endpoint:13','endpoint']]);
    if(pending) values.set('push_login_activation_pending','1');
    let sent=0;
    const ctx={
      window:{PUSH_MOBILE_CFG:{vapidKey:'public-key',swUrl:'/app/sw.js',registerUrl:'/app/profilo/device/register-here',userId:13},
        PushManager:{},Notification:{},isSecureContext:true,
        PushRegistration:{ensurePushSubscription:async()=>({subscription:{endpoint:'endpoint',getKey:()=>null}})}},
      navigator:{serviceWorker:{},userAgent:'iPhone',platform:'iPhone'},Notification:{permission:'granted'},
      localStorage:{getItem:k=>values.get(k)||null,setItem:(k,v)=>values.set(k,v),removeItem:k=>values.delete(k)},
      URLSearchParams,fetch:async()=>{sent++;return {ok,json:async()=>({ok})};}
    };
    vm.runInNewContext(script,ctx);
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(sent,expected);
    assert.equal(values.has('push_login_activation_pending'),pending&&!ok,'Clear pending only after confirmed registration');
  }
  console.log('PASS: PWA visibility, gesture, granted/denied/dismissed/error/unsupported and authenticated association/cache/retry');
})().catch(error=>{console.error(error);process.exitCode=1;});
