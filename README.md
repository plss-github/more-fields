# More Fields (GLPI 11)

Campos adicionais para qualquer item do GLPI, alternativa ao plugin Fields.

## Modelo

- **Campos** (`fielddefinitions`): definição global, reutilizável em vários blocos. Nome do sistema e tipo são imutáveis.
- **Listas de valores** (`choicelists`/`choices`): compartilhadas entre campos.
- **Blocos** (`containers`): só um agrupador (nome, escopo de entidade e regras).
- **Campos do bloco** (`containerfields`): cada campo escolhe onde aparece (formulário principal ou aba nomeada), em quais itens vale (`containerfielditemtypes`), e se é obrigatório ou somente leitura.
- **Regras** (`visibilityrules`): mostrar / ocultar / obrigatório / somente leitura, por bloco ou por campo, com
  condições por perfil ativo, entidade, categoria ITIL, status, tipo e valor de outro campo (E/OU).
- **Valores** (`values`): uma única tabela tipada. Sem classes geradas e sem DDL em runtime.

Perfil e entidade são resolvidos no servidor (o que está oculto nem chega ao HTML). Categoria, status e valor de
outro campo são reavaliados ao vivo por `public/js/morefields.js`. O servidor é sempre a autoridade na gravação:
campo oculto ou somente leitura nunca é gravado.

## Instalação (dev)

`docker-compose.yml` monta `./morefields` em `/var/www/glpi/plugins/morefields`:

    docker exec glpi11-app php bin/console plugin:install morefields -u glpi
    docker exec glpi11-app php bin/console plugin:activate morefields

Menu: Configurar > More Fields. Busca: cada campo vira uma coluna (opção `79000 + id do campo`).

## Salvar tudo (aba Campos do bloco)

Na aba **Campos** de um bloco, todas as linhas se editam de uma vez (ordem, itens, onde exibir, aba, obrigatório,
somente leitura) e um único **Salvar tudo** grava tudo, numa transação: se qualquer linha for recusada, nenhuma é gravada.
O botão mostra quantas linhas foram alteradas, as linhas alteradas ficam destacadas, e a página avisa se você tentar sair
com alterações não salvas. Excluir continua sendo por linha.

## Tipos de item

"Vale para os itens" (e o alvo de um campo "Item do GLPI") oferece cerca de 190 tipos, agrupados: ativos (inclusive os
personalizados), componentes (Cartão SIM, Memória, Disco...), gerência (Linhas, Contratos, Fornecedores, Racks...),
assistência, ferramentas, administração e os cadastros auxiliares do GLPI (tipos, modelos, categorias...). A lista vem dos
registros do próprio GLPI.

**Cadastro × unidade física.** "Cartão SIM" é o cadastro do chip (`DeviceSimcard`, tela `devicesimcard.form.php`); "Cartão SIM
(unidade física)" é cada chip real (`Item_DeviceSimcard`, tela `item_devicesimcard.form.php`, com serial, PIN, linha, local...).
O mesmo vale para memória, disco, bateria etc. (grupo *Componentes — unidades físicas*). O valor de um campo fica no tipo
escolhido: um campo ligado ao cadastro não aparece na unidade física, e vice-versa. Ficam de fora outras relações entre
itens e tarefas, que não têm formulário próprio.

## Navegação

Uma única entrada no menu (**Configurar > More Fields**). No topo de todas as páginas do plugin há uma barra de abas:
**Blocos de campos · Campos · Listas de valores · Configurações**.

## Configurações (More Fields > Configurações)

- **Ao desinstalar:** *manter os dados* (padrão) ou *apagar tudo*. Apagando, gera antes um backup
  (`files/_plugins/morefields/backups`) e cancela a desinstalação se o backup falhar.
- **Exigir obrigatórios em criações sem formulário** (API, importação): desligado por padrão. Vale só para campos do
  formulário principal; deixe desligado se algum caminho de criação não exibe os campos.
- **Integridade:** lista e corrige sobras de exclusões, valores duplicados e valores de itens/opções inexistentes.
- **Backups:** *Gerar backup agora* grava um arquivo `.jsonl.gz` em `files/_plugins/morefields/backups` (uma linha por
  registro, mais uma linha inicial com a lista de tabelas). A mesma tela lista os arquivos, com **Baixar** e **Restaurar**.
  Restaurar **substitui** todos os dados do plugin pelos do arquivo: o arquivo é validado inteiro antes (formato, tabelas e
  colunas), um backup de segurança do estado atual (`before-restore-…`) é gerado antes, e a troca é feita numa transação
  (se falhar, nada muda). Os backups gerados ao desinstalar com "apagar tudo" (`uninstall-…`) servem para recuperar:
  reinstale o plugin e restaure o arquivo. O backup cobre as tabelas do plugin, não as configurações da tela.

## Clonar, modelos e transferência

Clonar um item, criar a partir de modelo e transferir com cópia copiam os valores dos campos. Em cada campo,
**"Copiar ao clonar"** desliga a cópia (use para identificadores únicos, como o ID IC). O GLPI não oferece gancho de
plugin no clone: o item de origem é obtido da pilha de chamadas (`Injector::findCloneSource`); reteste a cada atualização do GLPI.

## Exclusões e concorrência

Excluir um campo com valores exige marcar a confirmação (a tela mostra quantos valores e itens serão perdidos).
Excluir uma lista usada por campos é recusado. A gravação de valores usa trava por item e campo (`GET_LOCK`) e transação.

## Integração com Formulários (GLPI 11)

Permite preencher campos do More Fields a partir de um formulário do catálogo de serviços:

- **Pergunta "Campo do More Fields"** (categoria própria no editor): escolha o campo; a pergunta usa o mesmo controle do campo (lista, data, usuário, sim/não…).
- **Destino "Campos adicionais (More Fields)"** (aba Destinos, grupo Propriedades; ligado por padrão): grava as respostas nos campos do Chamado, Problema ou Mudança criado.
- Só são oferecidos campos vinculados a Chamado, Problema ou Mudança; para cada item criado, só entram os campos que valem para aquele tipo.
- Respostas do formulário ignoram regras de visibilidade/somente leitura (quem preenche o formulário não vê a tela do item). Obrigatoriedade da pergunta funciona normalmente.
- Testado no GLPI 11.0.9. A API de Formulários é recente e pode mudar entre versões.

## Fora do MVP

Histórico de alterações dos valores, valor padrão, massive actions, import/export, tradução de rótulos,
árvore em listas de valores, direitos por perfil além do direito `config`, criação de chamado pelo formulário simplificado/catálogo.

## API para outros plugins

`GlpiPlugin\Morefields\Api` (use `class_exists()` antes):

- `Api::getTextFields(string $itemtype): array` — campos de texto/texto longo ativos que valem para o tipo de item (`id => rótulo`).
- `Api::describe(int $field_id): ?string` — rótulo do campo.
- `Api::setValue(string $itemtype, int $items_id, int $field_id, string $value): ?string` — grava o valor (valida campo ativo, tipo e tipo de item) e devolve `null` se deu certo, ou o motivo da falha. Ignora "somente leitura", pensado para preenchimento automático.

O plugin **assetprefixes** usa essa API para gravar a numeração em campos do More Fields.

## Licença

GPLv2 ou posterior (GPLv2+). Autor: Matheus Schmidt. Veja o arquivo `LICENSE`.
