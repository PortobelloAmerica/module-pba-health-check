<<<<<<< HEAD
# module-health-check
=======
# PBA/HealthCheck

Modulo Monitoramento de Parada de Crons com integracao Uptime Robot 2.4.x
Agora é possível registrar no log qual das tarefa do cron scheduler gerou uma exceção 500 no módulo de Health Check. Isso pode ser feito capturando os dados do cron que falhou (como o nome da tarefa) no momento em que a exceção é detectada e registrando essas informações nos logs do Magento.

## Instalação (modo local)

Copie para `app/code/PBA/HealthCheck` e execute:

## Config Settings

Go to Stores > Configuration > General > Health Check

```bash
bin/magento module:enable PBA_HealthCheck
bin/magento setup:upgrade
bin/magento c:f
>>>>>>> 507b642 (v1.0.0)
