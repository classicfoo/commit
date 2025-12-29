    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      window.addEventListener('DOMContentLoaded', () => {
        const alerts = document.querySelectorAll('.js-auto-dismiss');
        if (!alerts.length) {
          return;
        }
        window.setTimeout(() => {
          alerts.forEach((alert) => {
            alert.remove();
          });
        }, 4000);
      });
    </script>
  </body>
</html>
