/**
 * InterSoccer Reports and Rosters Admin Dashboard Script
 * Author: Jeremy Lee
 * Description: Minimal test dashboard to verify script loading.
 */

const { useState } = React;

const ReportsRostersDashboard = () => {
  const [message, setMessage] = useState('Dashboard Loaded');

  return (
    <div>
      <h2>{message}</h2>
    </div>
  );
};

jQuery(document).ready(function ($) {
  const root = document.getElementById('intersoccer-reports-rosters-root');
  if (root) {
    ReactDOM.render(<ReportsRostersDashboard />, root);
  } else {
    console.error('Root element not found');
  }
});