document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    document.documentElement.classList.add('js-enabled');
    initMobileMenu();
    initSmoothScroll();
    initBookingForm();
});

function initMobileMenu() {
    const toggle = document.querySelector('.mobile-toggle');
    const menu = document.querySelector('.nav-menu');
    if (!toggle || !menu) return;
    toggle.addEventListener('click', () => { menu.classList.toggle('active'); toggle.classList.toggle('active'); });
    menu.querySelectorAll('a').forEach(link => { link.addEventListener('click', () => { menu.classList.remove('active'); toggle.classList.remove('active'); }); });
}

function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href === '#' || href === '') return;
            const target = document.querySelector(href);
            if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    });
}

function initBookingForm() {
    const form = document.getElementById('booking-form');
    if (!form) return;
    const submitBtn = document.getElementById('submit-btn');
    const responseDiv = document.getElementById('form-response');
    const errorSpans = form.querySelectorAll('.error-message');
    
    function clearErrors() {
        errorSpans.forEach(span => span.textContent = '');
        form.querySelectorAll('.error').forEach(el => el.classList.remove('error'));
    }
    
    function showErrors(errors) {
        clearErrors();
        for (const [field, message] of Object.entries(errors)) {
            const errorSpan = form.querySelector(`[data-error="${field}"]`);
            if (errorSpan) errorSpan.textContent = message;
            let input;
            if (field === 'pets') input = form.querySelector('#pets');
            else if (field === 'gender') input = form.querySelector('input[name="gender"]');
            else input = form.querySelector(`[name="${field}"]`);
            if (input) input.classList.add('error');
        }
    }
    
    function showResponse(message, type = 'success') {
        if (!responseDiv) return;
        responseDiv.innerHTML = message;
        responseDiv.className = `form-response ${type}`;
        responseDiv.style.display = 'block';
        responseDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    
    function clientValidate() {
        const errors = {};
        const fullName = form.querySelector('#full_name')?.value.trim() || '';
        const phone = form.querySelector('#phone')?.value.trim() || '';
        const email = form.querySelector('#email')?.value.trim() || '';
        const birthDate = form.querySelector('#birth_date')?.value || '';
        const gender = form.querySelector('input[name="gender"]:checked')?.value || '';
        const pets = form.querySelector('#pets')?.selectedOptions || [];
        const agreed = form.querySelector('input[name="agreed"]:checked")?.value || '';
        
        if (!fullName) errors.full_name = 'ФИО обязательно';
        else if (fullName.length > 150) errors.full_name = 'ФИО не должно превышать 150 символов';
        else if (!/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u.test(fullName)) errors.full_name = 'Только буквы, пробелы и дефисы';
        if (!phone) errors.phone = 'Телефон обязателен';
        else if (!/^[\d\s\+\(\)-]{5,20}$/.test(phone)) errors.phone = 'Некорректный телефон';
        if (!email) errors.email = 'Email обязателен';
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = 'Некорректный email';
        if (!birthDate) errors.birth_date = 'Дата обязательна';
        else if (new Date(birthDate) > new Date()) errors.birth_date = 'Дата не может быть в будущем';
        if (!gender) errors.gender = 'Выберите пол';
        if (pets.length === 0) errors.pets = 'Выберите хотя бы одно животное';
        if (!agreed) errors.agreed = 'Нужно согласиться с условиями';
        return errors;
    }
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        clearErrors();
        if (responseDiv) responseDiv.style.display = 'none';
        const clientErrors = clientValidate();
        if (Object.keys(clientErrors).length > 0) { showErrors(clientErrors); return; }
        
        const originalHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
        submitBtn.disabled = true;
        
        const formData = new FormData(form);
        const data = {};
        for (const [key, value] of formData.entries()) {
            if (key === 'pets[]') { if (!data.pets) data.pets = []; data.pets.push(value); }
            else if (key !== 'csrf_token') data[key] = value;
        }
        
        const isLoggedIn = document.body.dataset.loggedIn === 'true';
        const userId = document.body.dataset.userId;
        let url = 'api.php'; let method = 'POST';
        if (isLoggedIn && userId) { url = `api.php?id=${userId}`; method = 'PUT'; }
        
        try {
            const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
            const result = await response.json();
            if (response.ok) {
                if (result.login && result.password) {
                    showResponse(`<h4>✅ Заявка принята!</h4><p><strong>🔑 Логин:</strong> <code>${escapeHtml(result.login)}</code></p><p><strong>🔒 Пароль:</strong> <code>${escapeHtml(result.password)}</code></p><p class="warning">Сохраните эти данные!</p><p><a href="login.php" class="btn btn-sm btn-primary">Войти</a></p>`, 'success');
                    form.reset();
                } else showResponse('<h4>✅ Данные обновлены!</h4>', 'success');
            } else if (response.status === 400 && result.errors) { showErrors(result.errors); showResponse('Исправьте ошибки', 'error'); }
            else if (response.status === 401) showResponse('Необходима авторизация. <a href="login.php">Войти</a>', 'error');
            else showResponse(`Ошибка: ${escapeHtml(result.error || 'Неизвестная')}`, 'error');
        } catch (error) { showResponse(`Ошибка сети: ${escapeHtml(error.message)}`, 'error'); }
        finally { submitBtn.innerHTML = originalHTML; submitBtn.disabled = false; }
    });
    
    async function loadUserData() {
        const isLoggedIn = document.body.dataset.loggedIn === 'true';
        const userId = document.body.dataset.userId;
        if (!isLoggedIn || !userId) return;
        try {
            const response = await fetch(`api.php?id=${userId}`);
            if (response.ok) {
                const data = await response.json();
                if (data.full_name) form.querySelector('#full_name').value = data.full_name;
                if (data.phone) form.querySelector('#phone').value = data.phone;
                if (data.email) form.querySelector('#email').value = data.email;
                if (data.birth_date) form.querySelector('#birth_date').value = data.birth_date;
                if (data.gender) { const radio = form.querySelector(`input[name="gender"][value="${data.gender}"]`); if (radio) radio.checked = true; }
                if (data.pets && Array.isArray(data.pets)) { const select = form.querySelector('#pets'); if (select) { for (const option of select.options) { option.selected = data.pets.includes(option.value); } } }
                if (data.biography) form.querySelector('#biography').value = data.biography;
                if (data.agreed) { const checkbox = form.querySelector('input[name="agreed"]'); if (checkbox) checkbox.checked = true; }
                if (submitBtn) submitBtn.innerHTML = '<i class="fas fa-save"></i> Обновить заявку';
            }
        } catch (error) { console.error('Ошибка загрузки:', error); }
    }
    loadUserData();
}