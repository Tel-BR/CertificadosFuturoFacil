# 1. Livro de Registro Digital com SQLite e Exportação Excel

Para garantir a integridade da numeração sequencial de Livro, Folha e Registro entre diferentes lotes de emissão, decidimos utilizar um banco SQLite local como fonte da verdade transacional, exportando automaticamente a planilha consolidada livro_registro_certificados.xlsx a cada emissão. Isso elimina riscos de corrupção ou perda de estado decorrentes de edições manuais concorrentes em planilhas sem abrir mão da facilidade de auditoria em Excel para o usuário.
