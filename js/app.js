// ============================================
// FinShare - Main JavaScript
// ============================================

// Sidebar toggle (mobile)
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

if (menuToggle) {
    menuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
    });
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
    });
}

// ============================================
// MODAL MANAGER
// ============================================
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('open');
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

// Close modal on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) {
            backdrop.classList.remove('open');
        }
    });
});

// ============================================
// TABS
// ============================================
function switchTab(tabId, btnEl, groupClass) {
    const group = btnEl.closest('.' + (groupClass || 'tabs'));
    group.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btnEl.classList.add('active');

    // Find tab panels
    const panel = document.getElementById(tabId);
    if (panel) {
        const container = panel.parentElement;
        container.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
        panel.style.display = 'block';
    }
}

// ============================================
// FORMAT CURRENCY (IDR)
// ============================================
function formatRupiah(amount) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
}

// ============================================
// CONFIRM DELETE
// ============================================
function confirmDelete(form) {
    if (confirm('Apakah Anda yakin ingin menghapus data ini?')) {
        form.submit();
    }
    return false;
}

// ============================================
// AUTO DISMISS ALERTS
// ============================================
document.querySelectorAll('.alert').forEach(alert => {
    setTimeout(() => {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    }, 4000);
});

// ============================================
// ANIMATED COUNTERS
// ============================================
function animateCounter(el) {
    const target = parseFloat(el.dataset.target || 0);
    const duration = 1000;
    const step = target / (duration / 16);
    let current = 0;

    const timer = setInterval(() => {
        current += step;
        if (current >= target) {
            current = target;
            clearInterval(timer);
        }
        el.textContent = formatRupiah(Math.round(current));
    }, 16);
}

document.querySelectorAll('[data-counter]').forEach(el => {
    animateCounter(el);
});

// ============================================
// PROGRESS BARS ANIMATION
// ============================================
document.querySelectorAll('.progress-fill[data-width]').forEach(bar => {
    setTimeout(() => {
        bar.style.width = bar.dataset.width + '%';
    }, 200);
});

// ============================================
// CSS BAR CHART (Dashboard)
// ============================================
function renderBarChart(data, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const maxVal = Math.max(...data.map(d => Math.max(d.income, d.expense)));

    let html = '';
    data.forEach(d => {
        const incH = Math.round((d.income / maxVal) * 110);
        const expH = Math.round((d.expense / maxVal) * 110);
        html += `
      <div class="bar-group">
        <div class="bar-wrap">
          <div class="bar income" style="height:${incH}px" title="Pemasukan: ${formatRupiah(d.income)}"></div>
          <div class="bar expense" style="height:${expH}px" title="Pengeluaran: ${formatRupiah(d.expense)}"></div>
        </div>
        <div class="bar-label">${d.label}</div>
      </div>`;
    });

    container.innerHTML = html;
}

// Render chart if data exists
if (window.chartData) {
    renderBarChart(window.chartData, 'barChart');
}

// ============================================
// DONUT CHART (CSS conic-gradient)
// ============================================
function renderDonut(data, chartId) {
    const el = document.getElementById(chartId);
    if (!el) return;

    const total = data.reduce((s, d) => s + d.value, 0);
    if (total === 0) return;

    let gradient = '';
    let prev = 0;
    data.forEach(d => {
        const pct = (d.value / total) * 100;
        gradient += `${d.color} ${prev}% ${prev + pct}%,`;
        prev += pct;
    });
    gradient = gradient.slice(0, -1);

    el.style.background = `conic-gradient(${gradient})`;
    el.style.borderRadius = '50%';
    el.style.outline = `8px solid var(--bg2)`;
}

if (window.donutData) {
    renderDonut(window.donutData, 'donutChart');
}

// ============================================
// FORM VALIDATION
// ============================================
document.querySelectorAll('form[data-validate]').forEach(form => {
    form.addEventListener('submit', function (e) {
        let valid = true;
        this.querySelectorAll('[required]').forEach(field => {
            if (!field.value.trim()) {
                field.style.borderColor = 'var(--danger)';
                valid = false;
            } else {
                field.style.borderColor = '';
            }
        });
        if (!valid) {
            e.preventDefault();
            alert('Harap isi semua field yang diperlukan.');
        }
    });
});

// ============================================
// NUMBER FORMAT INPUT
// ============================================
document.querySelectorAll('input[data-amount]').forEach(input => {
    input.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
});

// ============================================
// COPY INVITE CODE
// ============================================
function copyCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        showToast('Kode undangan disalin!', 'success');
    });
}

// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.cssText = `
    position: fixed; bottom: 1.5rem; right: 1.5rem;
    z-index: 9999; padding: 0.875rem 1.25rem;
    border-radius: 12px; max-width: 320px;
    animation: slideUp 0.3s ease both;
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
  `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.4s';
        setTimeout(() => toast.remove(), 400);
    }, 3000);
}

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    // Activate nav item based on current page
    const path = window.location.pathname;
    document.querySelectorAll('.nav-item').forEach(link => {
        if (link.getAttribute('href') && path.includes(link.getAttribute('href'))) {
            link.classList.add('active');
        }
    });
});