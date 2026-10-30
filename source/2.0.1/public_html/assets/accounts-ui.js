'use strict';
let logPage=1;
const actionLabels={login:'登录成功',login_failed:'登录失败',logout:'退出登录',site_saved:'保存网站设置',image_uploaded:'上传图片',image_deleted:'彻底删除图片',user_created:'创建管理员',user_updated:'修改管理员',user_deleted:'删除管理员',password_changed:'修改账号密码',password_change_failed:'修改密码失败',accounts_migrated:'升级账号登录',upgrade_failed:'账号升级验证失败',initial_credentials_updated:'更新默认登录信息'};
const fieldLabels={site_name:'后台网站名称',title:'标题',body:'正文',footer_text:'底部文字',title_size:'标题字号',title_weight:'标题字重',title_align:'标题对齐',body_size:'正文字号',body_weight:'正文字重',numbers:'WhatsApp号码',message:'预填消息',button_text:'按钮文案',button_id:'按钮ID',button_class:'按钮class',images:'头图',interval:'轮播间隔',tiktok_pixel_id:'TikTok像素ID',tiktok_enabled:'TikTok开关',tiktok_contact:'TikTok联系事件',meta_pixel_id:'Meta像素ID',meta_enabled:'Meta开关',meta_contact:'Meta联系事件'};
function displayTime(value){return value?new Date(value).toLocaleString('zh-CN',{timeZone:'Asia/Shanghai',hour12:false}):'尚未登录';}
async function readResource(name,query=''){
  const response=await fetch('/admin/api.php?resource='+name+query,{cache:'no-store',credentials:'same-origin'});
  const result=await response.json();
  if(!response.ok){if(response.status===401){authenticated=false;$('editor').inert=true;updateSave();}throw new Error(result.error||'读取失败。');}
  return result;
}
function renderUsers(users){
  $('user-list').replaceChildren();
  for(const user of users){
    const row=node('article',undefined,'user-row');
    const top=node('div',undefined,'token-info');top.append(node('h3',user.username),node('span',(user.role==='super'?'超级管理员':'普通管理员')+(user.enabled?'':' · 已停用'),'pill'));row.append(top);
    row.append(node('p','创建时间：'+displayTime(user.created_at)+'　最近登录：'+displayTime(user.last_login),'help'));
    const controls=node('div',undefined,'user-controls');
    const label=node('label','角色 ');const select=node('select');
    for(const [value,text] of [['admin','普通管理员'],['super','超级管理员']]){const option=node('option',text);option.value=value;select.append(option);}select.value=user.role;select.setAttribute('aria-label',user.username+' 的角色');label.append(select);
    const enabledLabel=node('label',undefined,'pixel-toggle');const enabled=node('input');enabled.type='checkbox';enabled.checked=user.enabled;enabled.setAttribute('aria-label',user.username+' 启用');enabledLabel.append(enabled,node('span','启用账号'));
    const save=node('button','保存权限','secondary');save.onclick=()=>operation(async()=>{const result=await api('update_user',{id:user.id,role:select.value,enabled:enabled.checked});if(result.logout){dirty=false;location.replace('/admin/');return;}await refreshUsers();notify('管理员权限已保存，该账号的旧会话已失效。');});
    const remove=node('button','删除账号','danger');remove.onclick=()=>{if(!confirm('删除账号“'+user.username+'”？该账号的所有会话将立即失效。'))return;operation(async()=>{const result=await api('delete_user',{id:user.id});if(result.logout){dirty=false;location.replace('/admin/');return;}await refreshUsers();notify('管理员已删除。');});};
    controls.append(label,enabledLabel,save,remove);row.append(controls);$('user-list').append(row);
  }
}
async function refreshUsers(){const result=await readResource('users');renderUsers(result.users);}
async function refreshLogs(){
  const filter=$('log-username').value.trim();
  const result=await readResource('logs','&page='+logPage+'&username='+encodeURIComponent(filter));
  $('log-list').replaceChildren();
  if(!result.logs.length)$('log-list').append(node('p','暂无符合条件的日志。','help'));
  for(const entry of result.logs){
    const row=node('article',undefined,'audit-entry');
    const top=node('div',undefined,'audit-heading');top.append(node('strong',entry.username+' · '+(actionLabels[entry.action]||entry.action)),node('time',displayTime(entry.at)));row.append(top);
    const detail=[];
    if(entry.target)detail.push('对象：'+entry.target);
    if(entry.details.fields?.length)detail.push('修改：'+entry.details.fields.map(key=>fieldLabels[key]||key).join('、'));
    if(entry.details.role)detail.push('角色：'+(entry.details.role==='super'?'超级管理员':'普通管理员'));
    if(typeof entry.details.enabled==='boolean')detail.push(entry.details.enabled?'账号已启用':'账号已停用');
    if(entry.details.locked)detail.push('连续失败达到限制，已暂停登录');
    row.append(node('p',detail.join('；')||'—'),node('small','IP：'+entry.ip));$('log-list').append(row);
  }
  $('logs-page').textContent='第 '+logPage+' 页，共 '+result.total+' 条';
  $('logs-prev').disabled=logPage<=1;$('logs-next').disabled=logPage*result.page_size>=result.total;
}
function initAccounts(){
  for(const button of document.querySelectorAll('[data-admin-view]'))button.onclick=()=>{
    const logs=button.dataset.adminView==='logs';$('users-view').hidden=logs;$('logs-view').hidden=!logs;
    document.querySelectorAll('[data-admin-view]').forEach(item=>item.classList.toggle('selected',item===button));
    operation(async()=>{if(logs){logPage=1;await refreshLogs();}else await refreshUsers();});
  };
  $('generate-password').onclick=()=>{const bytes=crypto.getRandomValues(new Uint8Array(18));$('new-user-password').value=btoa(String.fromCharCode(...bytes)).replaceAll('+','-').replaceAll('/','_').replaceAll('=','');$('new-user-password').type='text';notify('随机密码已生成，请妥善保存后再创建账号。');};
  $('create-user').onsubmit=event=>{event.preventDefault();operation(async()=>{
    await api('create_user',{username:$('new-username').value,password:$('new-user-password').value,role:$('new-user-role').value});
    $('create-user').reset();$('new-user-password').type='password';await refreshUsers();notify('管理员已创建，请将账号和初始密码交给对应使用者。');
  });};
  $('change-password').onsubmit=event=>{event.preventDefault();operation(async()=>{
    if($('new-password').value!==$('confirm-password').value)throw new Error('两次新密码不一致。');
    await api('change_password',{new_username:$('new-own-username').value,old_password:$('old-password').value,new_password:$('new-password').value,confirm_password:$('confirm-password').value});
    $('change-password').reset();dirty=false;alert('账号密码已更新，请使用新的账号密码重新登录。');location.replace('/admin/');
  });};
  $('change-initial-credentials').onclick=()=>{switchPanel('password');$('old-password').focus();};
  $('log-filter').onsubmit=event=>{event.preventDefault();logPage=1;operation(refreshLogs);};
  $('logs-prev').onclick=()=>{logPage--;operation(refreshLogs);};$('logs-next').onclick=()=>{logPage++;operation(refreshLogs);};
  // Renew only after real input/click activity. No background polling keeps an idle login alive.
  let lastRenewal=Date.now();
  const touch=()=>{if(!authenticated||busy||Date.now()-lastRenewal<60000)return;lastRenewal=Date.now();api('touch',{}).catch(error=>notify(error.message,true));};
  document.addEventListener('pointerdown',touch,{passive:true});document.addEventListener('keydown',touch,{passive:true});
}
