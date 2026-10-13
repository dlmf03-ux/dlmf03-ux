document.querySelectorAll('.qr-box').forEach((box) => {
  new QRCode(box, {
    text: box.dataset.url,
    width: 180,
    height: 180,
    colorDark: '#245f21',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M,
  });
});
