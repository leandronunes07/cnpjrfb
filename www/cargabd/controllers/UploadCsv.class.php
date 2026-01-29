<?php

class UploadCsv {

    private $dao    = null;
    private $arquivoCsv    = null;

    public function __construct(Dao $classDao, string $arquivoCsv)
    {
       $this->dao = $classDao;
       $this->arquivoCsv = $arquivoCsv;
    }

	public function executar(){
        $numRegistros = 0;
        $separador = '";"';
        $batchSize = 10000;
        
        $file = fopen($this->arquivoCsv, 'r');
        
        TPDOConnection::beginTransaction();
        
        try {
            while ( ($line = fgets ($file)) !== false ){
                //Limpando a linha
                $line = StringHelper::str2utf8($line);
                $line = substr($line,1);
                $line = substr($line,0,strrpos($line, '"'));
                
                if( !empty($line) ){
                    $line = explode($separador, $line);
                    $this->dao->insert( $line );
                    
                    $numRegistros++;
                    
                    if ($numRegistros % $batchSize == 0) {
                        TPDOConnection::commit();
                        TPDOConnection::beginTransaction();
                    }
                }
            }
            TPDOConnection::commit();
        } catch (Exception $e) {
            TPDOConnection::rollBack();
            fclose($file);
            throw $e;
        }

        fclose($file);
		return $numRegistros;
	}
}