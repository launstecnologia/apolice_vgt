CREATE TABLE imobiliarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    cnpj VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ativo',
    created_at DATETIME NOT NULL
);

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    tipo ENUM('admin', 'imobiliaria') NOT NULL,
    imobiliaria_id INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ativo',
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_usuarios_imobiliaria FOREIGN KEY (imobiliaria_id) REFERENCES imobiliarias(id)
);

CREATE TABLE apolices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    imobiliaria_id INT NOT NULL,
    cpf_cnpj_locatario VARCHAR(32) NOT NULL,
    endereco VARCHAR(255) NOT NULL,
    data_apolice DATE NOT NULL,
    arquivo_pdf VARCHAR(255) NOT NULL,
    hash_apolice VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_apolices_imobiliaria FOREIGN KEY (imobiliaria_id) REFERENCES imobiliarias(id)
);

CREATE INDEX idx_apolices_imobiliaria ON apolices (imobiliaria_id);
CREATE INDEX idx_apolices_cpf_cnpj ON apolices (cpf_cnpj_locatario);
CREATE INDEX idx_apolices_data ON apolices (data_apolice);
CREATE INDEX idx_apolices_combo ON apolices (imobiliaria_id, cpf_cnpj_locatario, data_apolice);
