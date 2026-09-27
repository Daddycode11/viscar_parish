document.querySelectorAll('img[data-logo-fallback]').forEach((image) => {
    image.addEventListener('error', () => image.classList.add('err'));
    image.addEventListener('load', () => image.classList.remove('err'));

    if (image.complete && image.naturalWidth === 0) {
        image.classList.add('err');
    }
});
