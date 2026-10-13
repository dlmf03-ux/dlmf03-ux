(() => {
  if (window.__knowledgeAssistantLoaded) return;
  window.__knowledgeAssistantLoaded = true;
  const script = document.currentScript;
  const api = script?.dataset.api || new URL('/api/chat', script?.src || location.href).href;
  const css = new URL('/widget.css', script?.src || location.href).href;
  const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = css; document.head.appendChild(link);

  const root = document.createElement('div'); root.id = 'knowledge-ai';
  root.innerHTML = `
    <button class="assistant-launch" aria-label="Открыть чат с ИИ-ассистентом"><span class="assistant-ai-mark">ИИ</span><span>Задать вопрос</span></button>
    <section class="assistant-panel" role="dialog" aria-label="Демонстрационный ИИ-ассистент" aria-hidden="true">
      <header><div><b>Демо-объект</b><small><span></span> Информационный ассистент</small></div><button class="assistant-close" aria-label="Закрыть">×</button></header>
      <div class="assistant-notice">Это демонстрационный пример с синтетическими данными. Не отправляйте персональные сведения. Ответы формируются по базе знаний и могут содержать ошибки.</div>
      <div class="assistant-messages" aria-live="polite"><div class="assistant-msg bot">Здравствуйте! Я демонстрационный информационный ассистент. Могу ответить на вопросы по примеру базы знаний. Чем помочь?</div></div>
      <form><textarea rows="1" maxlength="1500" placeholder="Например: какие услуги доступны?" aria-label="Ваш вопрос"></textarea><button type="submit" aria-label="Отправить">➤</button></form>
      <div class="assistant-foot">Демо-ответ по синтетической базе знаний</div>
    </section>`;
  document.body.appendChild(root);
  const launch = root.querySelector('.assistant-launch'), panel = root.querySelector('.assistant-panel'), close = root.querySelector('.assistant-close');
  const form = root.querySelector('form'), input = root.querySelector('textarea'), messages = root.querySelector('.assistant-messages');
  const history = [];
  const toggle = open => { panel.classList.toggle('open', open); panel.setAttribute('aria-hidden', String(!open)); if (open) input.focus(); };
  launch.onclick = () => toggle(!panel.classList.contains('open')); close.onclick = () => toggle(false);
  const add = (text, type) => { const el=document.createElement('div'); el.className=`assistant-msg ${type}`; el.textContent=text; messages.appendChild(el); messages.scrollTop=messages.scrollHeight; return el; };
  const addTyping = () => {
    const el=document.createElement('div');
    el.className='assistant-msg bot assistant-typing';
    el.setAttribute('role','status');
    el.setAttribute('aria-label','ИИ-ассистент печатает');
    el.innerHTML='<span class="assistant-typing-label">Ассистент печатает</span><span class="assistant-typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>';
    messages.appendChild(el); messages.scrollTop=messages.scrollHeight; return el;
  };
  form.addEventListener('submit', async e => {
    e.preventDefault(); const text=input.value.trim(); if (!text) return; input.value=''; add(text,'user');
    const wait=addTyping(); form.classList.add('busy');
    try {
      const controller=new AbortController(); const timer=setTimeout(()=>controller.abort(),30000);
      const res=await fetch(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:text,history:history.slice(-6)}),signal:controller.signal});
      clearTimeout(timer);
      const data=await res.json(); wait.remove(); add(data.answer || 'Не удалось получить ответ.','bot');
      history.push({role:'user',content:text},{role:'assistant',content:data.answer || ''});
    } catch (_) { wait.remove(); add('Демонстрационный сервис временно недоступен.','bot'); }
    finally { form.classList.remove('busy'); input.focus(); }
  });
  input.addEventListener('keydown', e => { if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
})();
