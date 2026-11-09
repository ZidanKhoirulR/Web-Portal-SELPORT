document.addEventListener('DOMContentLoaded', () => {
    initFireflies();

    const tl = anime.timeline({
        easing: 'easeOutQuart'
    });

    tl
        .add({
            targets: '.header-logo',
            opacity: [0, 1],
            scale: [0.5, 1],
            filter: ['blur(20px)', 'blur(0px)'],
            duration: 2500,
            delay: 800
        })
        .add({
            targets: '#reveal-badge span',
            opacity: [0, 1],
            translateY: [40, 0],
            rotateX: [-90, 0],
            delay: anime.stagger(150),
            duration: 1200
        }, '-=1500')
        .add({
            targets: '#reveal-title span',
            opacity: [0, 1],
            translateY: [60, 0],
            rotateX: [-90, 0],
            delay: anime.stagger(100),
            duration: 1500
        }, '-=1000')
        .add({
            targets: '.auth-options',
            opacity: [0, 1],
            translateY: [30, 0],
            duration: 800
        }, '-=2000'); // SYNC WITH TITLE START

    anime({
        targets: '.header-logo',
        filter: ['drop-shadow(0 0 20px rgba(98, 160, 253, 0.4))', 'drop-shadow(0 0 40px rgba(98, 160, 253, 0.8))'],
        duration: 3000,
        direction: 'alternate',
        loop: true,
        easing: 'easeInOutSine'
    });

    const btn = document.querySelector('.btn-primary');
    if (btn) {
        window.addEventListener('mousemove', (e) => {
            const rect = btn.getBoundingClientRect();
            const btnX = rect.left + rect.width / 2;
            const btnY = rect.top + rect.height / 2;
            const dist = Math.sqrt(Math.pow(e.clientX - btnX, 2) + Math.pow(e.clientY - btnY, 2));

            if (dist < 200) {
                const x = (e.clientX - btnX) * 0.35;
                const y = (e.clientY - btnY) * 0.35;
                anime({
                    targets: btn,
                    translateX: x,
                    translateY: y,
                    scale: 1.1,
                    boxShadow: '0 20px 50px rgba(255, 255, 255, 0.3)',
                    duration: 400,
                    easing: 'easeOutQuad'
                });
            } else {
                anime({
                    targets: btn,
                    translateX: 0,
                    translateY: 0,
                    scale: 1,
                    boxShadow: '0 10px 30px rgba(255, 255, 255, 0.1)',
                    duration: 800,
                    easing: 'easeOutElastic(1, .5)'
                });
            }
        });

    }

});

function handleAuthTransition(event, btn, url) {
    const overlay = document.getElementById('transition-overlay');
    const hero = document.querySelector('.hero');
    if (!overlay || !hero) {
        window.location.href = url;
        return;
    }

    const rect = btn.getBoundingClientRect();
    const btnX = rect.left + rect.width / 2;
    const btnY = rect.top + rect.height / 2;

    const xPercent = (btnX / window.innerWidth) * 100;
    const yPercent = (btnY / window.innerHeight) * 100;

    hero.style.transformOrigin = `${xPercent}% ${yPercent}%`;

    overlay.style.position = 'fixed';
    overlay.style.left = `${btnX}px`;
    overlay.style.top = `${btnY}px`;
    overlay.style.width = '100vw';
    overlay.style.height = '100vw';
    overlay.style.marginLeft = '-50vw';
    overlay.style.marginTop = '-50vw';
    overlay.style.opacity = '1';
    overlay.style.transform = 'scale(0)';
    overlay.style.borderRadius = '50%';
    overlay.style.background = 'white';
    overlay.style.zIndex = '9999';

    const tl = anime.timeline();

    tl
        .add({
            targets: hero,
            scale: 0.9,
            duration: 500,
            easing: 'easeOutQuad'
        })
        .add({
            targets: hero,
            scale: 1400, // UNIVERSE SCALE (1400x) - THEORETICAL LIMIT
            opacity: [1, 0],
            duration: 2500, // Slow start...
            easing: 'easeInExpo' // ...explosive finish
        }, '-=200')
        .add({
            targets: overlay,
            scale: [0, 50], // Match extreme intensity
            duration: 2500,
            easing: 'easeInExpo',
            complete: () => {
                setTimeout(() => {
                    window.location.href = url;
                }, 800);
            }
        }, '-=2500'); // Parallel execution
}

function initFireflies() {
    const canvas = document.createElement('canvas');
    canvas.id = 'fireflies';
    canvas.style.position = 'fixed';
    canvas.style.top = '0';
    canvas.style.left = '0';
    canvas.style.width = '100%';
    canvas.style.height = '100%';
    canvas.style.pointerEvents = 'none';
    canvas.style.zIndex = '1';
    document.body.appendChild(canvas);

    const ctx = canvas.getContext('2d');
    let width, height;

    function resize() {
        width = canvas.width = window.innerWidth;
        height = canvas.height = window.innerHeight;
    }
    window.addEventListener('resize', resize);
    resize();

    const particles = [];
    for (let i = 0; i < 40; i++) {
        particles.push({
            x: Math.random() * width,
            y: Math.random() * height,
            size: Math.random() * 2 + 1,
            vx: (Math.random() - 0.5) * 0.5,
            vy: (Math.random() - 0.5) * 0.5,
            history: []
        });
    }

    function animate() {
        ctx.clearRect(0, 0, width, height);
        particles.forEach(p => {
            p.x += p.vx;
            p.y += p.vy;
            if (p.x < 0) p.x = width;
            if (p.x > width) p.x = 0;
            if (p.y < 0) p.y = height;
            if (p.y > height) p.y = 0;

            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(255, 255, 255, ${0.2 + Math.random() * 0.3})`;
            ctx.shadowBlur = 10;
            ctx.shadowColor = 'white';
            ctx.fill();
        });
        requestAnimationFrame(animate);
    }
    animate();
}

function setupLogoInteractions() {
    const logoBtn = document.getElementById('logoBtn');
    const authOptions = document.getElementById('authOptions');
    if (logoBtn && authOptions) {
        let hideTimeout;
        logoBtn.addEventListener('mouseenter', () => {
            clearTimeout(hideTimeout);
            authOptions.classList.add('show');
        });
        logoBtn.addEventListener('mouseleave', () => {
            hideTimeout = setTimeout(() => authOptions.classList.remove('show'), 500);
        });
        authOptions.addEventListener('mouseenter', () => clearTimeout(hideTimeout));
        authOptions.addEventListener('mouseleave', () => {
            hideTimeout = setTimeout(() => authOptions.classList.remove('show'), 500);
        });
    }
}
