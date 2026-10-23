<script>
    window.PennantExperiments = (function () {
        var token = @json(csrf_token());
        var routes = { expose: @json(route('pennant-experiments.track.expose')), convert: @json(route('pennant-experiments.track.convert')) };
        function post(url, body) {
            return fetch(url, {
                method: 'POST', credentials: 'same-origin', keepalive: true,
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body)
            }).then(function (response) { return response.ok ? response.json() : Promise.reject(response); });
        }
        return {
            // Record that the current visitor/user saw the experiment; resolves to { variant }.
            expose: function (experiment) { return post(routes.expose, { experiment: experiment }); },
            // Record a conversion for a goal; resolves to { recorded: true|false }.
            convert: function (experiment, goal) { return post(routes.convert, { experiment: experiment, goal: goal || 'conversion' }); }
        };
    })();
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-experiment-convert]');
        if (el) { window.PennantExperiments.convert(el.getAttribute('data-experiment-convert'), el.getAttribute('data-experiment-goal') || 'conversion'); }
    });
</script>
