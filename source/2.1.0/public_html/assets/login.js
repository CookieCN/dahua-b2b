'use strict';
(() => {
  const form=document.getElementById('login-form');
  const message=document.getElementById('login-message');
  let csrf,challengeId;
  const answer=document.getElementById('math-answer');
  const refresh=document.getElementById('refresh-challenge');
  function setChallenge(challenge){
    if(!challenge || typeof challenge.id!=='string' || typeof challenge.question!=='string')throw new Error('校验题目加载失败，请刷新页面。');
    challengeId=challenge.id;
    document.getElementById('math-question').textContent=challenge.question;
    answer.value='';
  }
  if(location.search)history.replaceState(null,'','/admin/');
  const setMessage=(text,error=true)=>{document.getElementById('login-message-text').textContent=text;message.hidden=false;message.className=error?'login-message dismissible-message error':'login-message dismissible-message';};
  document.getElementById('dismiss-login-message').onclick=()=>{message.hidden=true;};
  async function ready(){
    refresh.disabled=true;
    document.querySelectorAll('button[type="submit"]').forEach(button=>button.disabled=true);
    try{
      const response=await fetch('/admin/api.php?resource=bootstrap',{cache:'no-store'});
      const result=await response.json();if(!response.ok)throw new Error(result.error||'登录页面载入失败。');
      csrf=result.csrf;setChallenge(result.challenge);
      document.getElementById('upgrade-form').hidden=!result.migration_required;
      form.hidden=result.migration_required;
      document.getElementById('login-description').textContent=result.migration_required?'旧版 token 站点需要一次账号升级，网站内容会保留。':'使用你的管理员账号登录，连续 7 天无操作后需要重新登录。';
      document.querySelectorAll('button[type="submit"]').forEach(button=>button.disabled=false);
    }finally{refresh.disabled=false;}
  }
  async function submit(event,action,body){
    event.preventDefault();const button=event.target.querySelector('button[type="submit"]');button.disabled=true;refresh.disabled=true;
    let renewed=false;
    try{
      const response=await fetch('/admin/api.php?action='+action,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});
      const result=await response.json();
      if(result.challenge){setChallenge(result.challenge);renewed=true;}
      if(!response.ok)throw new Error(result.error||'操作失败，请重试。');
      if(action==='login'){location.replace('/admin/');return;}
      event.target.reset();event.target.hidden=true;form.hidden=false;
      await ready();
      setMessage('升级完成。请使用 admin 和刚设置的密码登录。',false);
      if(result.credentials?.length){
        const box=document.getElementById('migration-credentials');box.hidden=false;
        box.textContent='以下原普通管理员已分配新账号密码，请立即保存（仅本次展示）：\n'+result.credentials.map(entry=>entry.username+'  '+entry.password).join('\n');
      }
    }catch(error){
      setMessage(error.message);
      if(action==='login'){
        if(!renewed){try{await ready();}catch{challengeId=null;setMessage('校验题目加载失败，请刷新页面后重试。');}}
        answer.focus();
      }
    }finally{button.disabled=action==='login'&&!challengeId;refresh.disabled=false;}
  }
  form.onsubmit=event=>submit(event,'login',{username:document.getElementById('login-username').value,password:document.getElementById('login-password').value,challenge_id:challengeId,challenge_answer:answer.value});
  refresh.onclick=async()=>{try{await ready();answer.focus();}catch(error){setMessage(error.message);}};
  document.getElementById('upgrade-form').onsubmit=event=>submit(event,'upgrade',{legacy_token:document.getElementById('legacy-token').value,new_password:document.getElementById('upgrade-password').value,confirm_password:document.getElementById('upgrade-confirm').value});
  document.getElementById('show-password').onclick=()=>{const input=document.getElementById('login-password');input.type=input.type==='password'?'text':'password';document.getElementById('show-password').textContent=input.type==='password'?'显示密码':'隐藏密码';};
  ready().catch(error=>setMessage(error.message));
})();
