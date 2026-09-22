const menu = document.querySelector('.menu-toggle');
const nav = document.querySelector('#nav');
menu?.addEventListener('click', () => {
  const open = menu.getAttribute('aria-expanded') !== 'true';
  menu.setAttribute('aria-expanded', String(open));
  menu.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
  nav.classList.toggle('open', open);
});
document.addEventListener('keydown', e => { if(e.key === 'Escape' && nav?.classList.contains('open')) {nav.classList.remove('open');menu.setAttribute('aria-expanded','false');menu.setAttribute('aria-label','Open navigation');menu.focus();} });
const year = document.querySelector('#year');
if (year) year.textContent = new Date().getFullYear();
const notice = document.querySelector('#notice');
const notices = {
  book: ['The next chapter is coming.', 'These are provisional titles and concept covers. The final Kindle listings and purchase links will be added when the series is ready.'],
  companion: ['Still taking shape.', 'The companion is in development. Pricing, account access, and a live AI connection are not available in this preview. Contact Selina if you would like to ask about it.']
};
document.querySelectorAll('[data-notice]').forEach(button => button.addEventListener('click', () => {
  const [title, description] = notices[button.dataset.notice];
  document.querySelector('#notice-title').textContent = title;
  document.querySelector('#notice-text').textContent = description;
  notice.showModal();
}));
notice?.querySelector('.close')?.addEventListener('click', () => notice.close());
notice?.addEventListener('click', e => { if(e.target === notice) {const r=notice.getBoundingClientRect(); if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom) notice.close();} });
const examples = {
  boundaries: ['I keep saying yes when I mean no.', 'What are you afraid might change if you said what you meant?', 'Consider one recent example. What did you want to say, and what did you feel responsible for protecting?'],
  decision: ['I’m going in circles over a decision.', 'Which part is uncertain: what might happen, or what matters most to you?', 'Try separating the facts you have from the outcome you hope for. What would you need to learn before choosing?'],
  work: ['A conversation at work is bothering me.', 'What was actually said—and what meaning did you give it?', 'Start with the words you remember. Then name the expectation or boundary that may have been left unspoken.']
};
const conversation=document.querySelector('#demo-conversation');
const original=conversation?.innerHTML;
document.querySelectorAll('[data-prompt]').forEach(button => button.addEventListener('click',()=>{
 const [question, reply, followup]=examples[button.dataset.prompt];
 conversation.replaceChildren();
 for(const [copy,cls] of [[question,'user-message'],[reply,'companion-message'],[followup,'']]) {const p=document.createElement('p');p.textContent=copy;p.className=cls;conversation.append(p);}
 document.querySelectorAll('[data-prompt]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));
}));
document.querySelector('.reset-demo')?.addEventListener('click',()=>{conversation.innerHTML=original;document.querySelectorAll('[data-prompt]').forEach(b=>b.setAttribute('aria-pressed','false'));});
