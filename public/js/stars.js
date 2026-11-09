
function generateFireflies(count, colorVariation = true) {
    const shadows = [];
    const colors = [
        '#FFE55C', // Soft yellow
        '#FFD700', // Gold
        '#FFA500', // Orange
        '#FFEB3B', // Bright yellow
        '#FFB74D'  // Light orange
    ];

    for (let i = 0; i < count; i++) {
        const x = Math.floor(Math.random() * 2000);
        const y = Math.floor(Math.random() * 2000);
        const color = colorVariation ? colors[Math.floor(Math.random() * colors.length)] : colors[0];
        shadows.push(`${x}px ${y}px ${color}`);
    }
    return shadows.join(', ');
}

document.addEventListener('DOMContentLoaded', function () {
    const stars1 = document.getElementById('stars');
    const stars2 = document.getElementById('stars2');
    const stars3 = document.getElementById('stars3');

    if (stars1) {
        stars1.style.boxShadow = generateFireflies(700);
        const after1 = document.createElement('style');
        after1.innerHTML = `#stars:after { box-shadow: ${generateFireflies(700)}; }`;
        document.head.appendChild(after1);
    }

    if (stars2) {
        stars2.style.boxShadow = generateFireflies(200);
        const after2 = document.createElement('style');
        after2.innerHTML = `#stars2:after { box-shadow: ${generateFireflies(200)}; }`;
        document.head.appendChild(after2);
    }

    if (stars3) {
        stars3.style.boxShadow = generateFireflies(100);
        const after3 = document.createElement('style');
        after3.innerHTML = `#stars3:after { box-shadow: ${generateFireflies(100)}; }`;
        document.head.appendChild(after3);
    }
});
