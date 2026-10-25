import { createRoot } from 'react-dom/client'
import App from './App'
import './admin.css'

const node = document.getElementById('wpi_wcrds-admin-root')
if (node) {
  createRoot(node).render(<App />)
}
