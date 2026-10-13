document.querySelector('.menu-toggle')?.addEventListener('click',function(){document.querySelector('.sidebar')?.classList.toggle('open')});
document.querySelectorAll('.archive-open').forEach(function(btn){btn.addEventListener('click',function(){
  var d=document.querySelector('#archive-dialog');document.querySelector('#archive-type').value=btn.dataset.type;
  document.querySelector('#archive-id').value=btn.dataset.id;d.showModal();
})});
document.querySelector('[data-close-dialog]')?.addEventListener('click',function(){document.querySelector('#archive-dialog').close()});

if(window.servicesReady){
  var cart=[],list=document.querySelector('#service-list'),cartEl=document.querySelector('#cart-items');
  var fmt=function(c){return new Intl.NumberFormat('ru-RU',{style:'currency',currency:'RUB'}).format(c/100)};
  var esc=function(s){return String(s).replace(/[&<>"']/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]})};
  var render=function(){
    if(!cart.length) cartEl.innerHTML='<div class="empty compact">Выберите процедуры слева</div>';
    else cartEl.innerHTML=cart.map(function(x,i){
      return '<div class="cart-row"><div class="cart-row-head"><b>'+esc(x.name)+'</b><button type="button" data-remove="'+i+'" aria-label="Убрать">×</button></div><div class="cart-controls"><input type="number" min="1" max="99" value="'+x.quantity+'" data-qty="'+i+'" title="Количество"><input value="'+esc(x.room)+'" data-room="'+i+'" placeholder="Кабинет №"><input class="scheduled" type="datetime-local" value="'+x.scheduled_at+'" data-time="'+i+'" title="Дата и время"></div><div class="cart-line-total">'+x.quantity+' × '+fmt(x.price)+' = '+fmt(x.quantity*x.price)+'</div></div>';
    }).join('');
    var total=cart.reduce(function(s,x){return s+x.quantity*x.price},0);
    document.querySelector('#cart-count').textContent=cart.length+' поз.';
    document.querySelector('#cart-total').textContent=fmt(total);
    document.querySelector('#save-order').disabled=!cart.length;
    document.querySelector('#items-json').value=JSON.stringify(cart.map(function(x){return {service_id:x.id,quantity:x.quantity,room:x.room,scheduled_at:x.scheduled_at}}));
  };
  list.addEventListener('click',function(e){var b=e.target.closest('.service-card');if(!b)return;var found=cart.find(function(x){return x.id===+b.dataset.id});if(found)found.quantity++;else cart.push({id:+b.dataset.id,name:b.dataset.name,price:+b.dataset.price,quantity:1,room:b.dataset.room||'',scheduled_at:''});render()});
  cartEl.addEventListener('change',function(e){var i;if(e.target.dataset.qty!==undefined){i=+e.target.dataset.qty;cart[i].quantity=Math.max(1,+e.target.value||1)}if(e.target.dataset.room!==undefined){i=+e.target.dataset.room;cart[i].room=e.target.value}if(e.target.dataset.time!==undefined){i=+e.target.dataset.time;cart[i].scheduled_at=e.target.value}render()});
  cartEl.addEventListener('click',function(e){if(e.target.dataset.remove!==undefined){cart.splice(+e.target.dataset.remove,1);render()}});
  var filter=function(){var text=document.querySelector('#service-search').value.toLowerCase(),cat=document.querySelector('#category-tabs .active')?.dataset.category||'';document.querySelectorAll('.service-card').forEach(function(x){x.hidden=!!((text&&!x.dataset.name.toLowerCase().includes(text))||(cat&&x.dataset.category!==cat))})};
  document.querySelector('#service-search').addEventListener('input',filter);
  document.querySelector('#category-tabs').addEventListener('click',function(e){if(e.target.tagName!=='BUTTON')return;document.querySelectorAll('#category-tabs button').forEach(function(b){b.classList.remove('active')});e.target.classList.add('active');filter()});
  document.querySelector('#order-form').addEventListener('submit',function(e){render();if(!cart.length)e.preventDefault()});
}
