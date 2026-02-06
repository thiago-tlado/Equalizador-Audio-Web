const TEMPO_MAXIMO = 10; // segundos
const PLOT_AUDIO = 4096; // Máximo de pontos no gráfico de áudio
window.teste = false;

let mediaRecorder;
let audioChunks = [];
let recording = false;
let timer;
let timerInterval;
let audioChart;
let fftChart;
let equalizadoChart;
let bilateralChart;
let reconstruidoChart;

const recordBtn = document.getElementById('recordBtn');
const equalizeBtn = document.getElementById('equalizeBtn');
const statusDiv = document.getElementById('status');
const audioFileInput = document.getElementById('audioFile');
const timerDisplay = document.getElementById('timerDisplay');
const audioInfoContent = document.getElementById('audioInfoContent');
const calcInfoContent = document.getElementById('calcInfoContent');
const info = document.getElementById('info');
const addBandsBtn = document.getElementById('addBandsBtn');
const bandsContainer = document.getElementById('bandsContainer');
const testeBtn = document.getElementById('testeBtn');

function chartOptions(maxVal, minVal) {
    const options = {
        responsive: true,
        plugins: {
            legend: { display: false }
        },
        scales: {
            x: { 
                display: true,
                ticks: {
                    autoSkip: true,
                    maxTicksLimit: 10
                }
            },
            y: { 
                min: minVal, 
                max: maxVal 
            }
        }
    };
    return options;
}

async function plotarGrafico(chartData, chartLabels, nome) {
    const chartCanvas = document.getElementById(nome);
    const datasets = [{
        label: 'Amplitude',
        data: [],
        borderColor: 'rgba(75,192,192,1)',
        backgroundColor: 'rgba(75,192,192,0.2)',
        pointRadius: 0,
        borderWidth: 1,
        fill: false,
        tension: 0.1
    }];

    async function criarCanvas(tipo) {
        const maxVal = Math.max(...chartData);
        const minVal = Math.min(...chartData);
        datasets[0].data = chartData;

        return await new Chart(chartCanvas, {
            type: tipo,
            data: { labels: chartLabels, datasets: datasets },
            options: chartOptions(maxVal*1.1, minVal*1.1)
        });
    }

    switch(nome) {
        case 'audioChart':
            if (audioChart) audioChart.destroy();
            audioChart = await criarCanvas('line');
            break;
        case 'fftChart':
            if (fftChart) fftChart.destroy();
            fftChart = await criarCanvas('bar');
            break;
        case 'equalizadoChart':
            if (equalizadoChart) equalizadoChart.destroy();
            equalizadoChart = await criarCanvas('bar');
            break;
        case 'bilateralChart':
            if (bilateralChart) bilateralChart.destroy();
            bilateralChart = await criarCanvas('bar');
            break;
        case 'reconstruidoChart':
            if (reconstruidoChart) reconstruidoChart.destroy();
            reconstruidoChart = await criarCanvas('line');
            break;
    }
}

async function zerarGrafico() {
    await plotarGrafico([0],[0],'audioChart');
    await plotarGrafico([0],[0],'fftChart');
    await plotarGrafico([0],[0],'equalizadoChart');
    await plotarGrafico([0],[0],'bilateralChart');
    await plotarGrafico([0],[0],'reconstruidoChart');
}
zerarGrafico();

async function reconstruirAudio(chartData, sampleRate) {
    info.innerHTML = "Aplicando reconstrução...";
    const step = (chartData.length > PLOT_AUDIO) ? Math.ceil(chartData.length / PLOT_AUDIO) : 1;
    const reducedChartData = [];
    const chartLabels = []; 

    for (let i = 0; i < chartData.length; i+= step) {
        reducedChartData.push(chartData[i]);  
        chartLabels.push(i);               
    }

    plotarGrafico(reducedChartData, chartLabels, 'reconstruidoChart');
    info.innerHTML = "Gráfico reconstruído plotado.";
    if(window.teste) return; // Não faz upload no modo de teste

    const audioContext = new (window.AudioContext || window.webkitAudioContext)();
    const buffer = audioContext.createBuffer(1, chartData.length, sampleRate);
    buffer.copyToChannel(new Float32Array(chartData), 0);

    function encodeWAV(audioBuffer) {
        const numChannels = audioBuffer.numberOfChannels;
        const sampleRate = audioBuffer.sampleRate;
        const format = 1;
        const bitDepth = 16;

        let samples = audioBuffer.getChannelData(0);
        let bufferLength = samples.length * (bitDepth / 8);
        let wavBuffer = new ArrayBuffer(44 + bufferLength);
        let view = new DataView(wavBuffer);

        function writeString(view, offset, string) {
            for (let i = 0; i < string.length; i++) {
                view.setUint8(offset + i, string.charCodeAt(i));
            }
        }

        writeString(view, 0, 'RIFF');
        view.setUint32(4, 36 + bufferLength, true);
        writeString(view, 8, 'WAVE');
        writeString(view, 12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, format, true);
        view.setUint16(22, numChannels, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * numChannels * (bitDepth / 8), true);
        view.setUint16(32, numChannels * (bitDepth / 8), true);
        view.setUint16(34, bitDepth, true);

        writeString(view, 36, 'data');
        view.setUint32(40, bufferLength, true);
        let offset = 44;

        for (let i = 0; i < samples.length; i++, offset += 2) {
            let s = Math.max(-1, Math.min(1, samples[i]));
            view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
        }

        return new Blob([view], { type: 'audio/wav' });
    }

    const wavBlob = encodeWAV(buffer);
    let nome = 'rec';
    if(window.ultimoArquivo) nome = window.ultimoArquivo.replace('.wav','_rec');    
    await uploadAudio(wavBlob, nome);
    info.innerHTML = "Upload de áudio IFFT finalizado.";   
}

async function realizarIFFT(bandas) {  
    let post = JSON.stringify({ nome: window.ultimoArquivo, bandas: bandas, processados: 0 });
    info.innerHTML = "Calculando IFFT...";
    console.log('Bandas enviadas para IFFT:', bandas);
    let processar = true;

    while(processar) {
        await fetch('ifft.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: post
        })
        .then(async res => await res.json())
        .then(json => {
            console.log('Resposta da IFFT:', json);   
            processar = json.processo;

            if(processar) {
                info.innerHTML = "Progresso IFFT: " + json.processados + " / " + json.blocos;
                post = JSON.stringify({ nome: window.ultimoArquivo, bandas: bandas });
            } else {
                info.innerHTML = "Processamento IFFT concluído...";
                plotarGrafico(json.magnitudes, json.frequencias, 'equalizadoChart');
                plotarGrafico(json.magnitudesBI, json.frequenciasBI, 'bilateralChart');
                reconstruirAudio(json.reconstruido, json.amostragem);
                equalizeBtn.disabled = false;
                recordBtn.disabled = false;
                testeBtn.disabled = false;
            }                
        })
        .catch((e) => {
            console.log('Erro na IFFT.', e);
            processar = false;
        });
    }
}

async function enviarParaFFT(channelData, FreqAmostragem) {  
    info.innerHTML = "Calculando FFT...";
    const blockSize = document.getElementById('windowSelect').value || 256;
    //console.log('Dados do sinal:', channelData);
    
    if(blockSize > (channelData.length * 2)) {
        alert('O tamanho do bloco não pode ser maior que (2x) o número de amostras.');
        equalizeBtn.disabled = false;
        recordBtn.disabled = false;
        return;
    }

    let post = JSON.stringify({ dados: channelData, blockSize: blockSize,
        amostragem: FreqAmostragem, nome: window.ultimoArquivo });
    let processar = true;
    console.log('Nome do arquivo enviado para FFT:', window.ultimoArquivo);

    while(processar) {          
        await fetch('fft.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: post
        })
        .then(async res => await res.json())
        .then(json => {
            console.log('Resposta da FFT:', json);
            processar = json.processo;            

            if(processar) {
                info.innerHTML = "Progresso FFT: " + json.cnt + " / " + json.total;
                post = JSON.stringify({ nome: window.ultimoArquivo, blockSize: blockSize });
            } else {
                info.innerHTML = "Processamento FFT concluído...";
                plotarGrafico(json.magnitudes, json.frequencias, 'fftChart');
                equalizeBtn.disabled = false;
                recordBtn.disabled = false;
                testeBtn.disabled = false;

                calcInfoContent.innerHTML = `
                    <b>Grupos:</b> ${json.grupos}<br>
                    <b>Máximo por grupo:</b> ${json.tamanhoGrupo}<br>
                    <b>Total de blocos:</b> ${json.blocos}<br>
                    <b>Tamanho do bloco:</b> ${json.tamanhoBloco}<br>
                    <b>Nº de amostras:</b> ${json.amostras}<br>
                `;
            }                      
        })
        .catch((e) => {
            console.log('Erro ao enviar dados para FFT.', e);
            processar = false;
        });
    }
}

async function criarGraficoAudio(blob) {
    const reader = new FileReader();
    info.innerHTML = "Processando áudio...";
    
    await new Promise((resolve) => {
        reader.onload = function(e) {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            audioContext.decodeAudioData(e.target.result, function(buffer) {
                const channelData = buffer.getChannelData(0);

                audioInfoContent.innerHTML = `
                    <b>Áudio:</b> ${PLOT_AUDIO} barras<br>
                    <b>Canais:</b> ${buffer.numberOfChannels}<br>
                    <b>Duração:</b> ${buffer.duration.toFixed(2)} s<br>
                    <b>Período:</b> ${((1 / buffer.sampleRate) * 1000000).toFixed(3)} us<br>
                    <b>Amostragem:</b> ${buffer.sampleRate} Hz<br>
                    <b>Nº de amostras:</b> ${channelData.length}<br>
                `;

                const step = Math.ceil(channelData.length / PLOT_AUDIO);
                const chartData = [];
                const chartLabels = [];

                for (let i = 0; i < channelData.length; i+= step) {
                    chartData.push(channelData[i]);  
                    chartLabels.push(i);               
                }

                chartData.push(channelData[channelData.length - 1]);
                chartLabels.push(channelData.length - 1);
               // console.log('Dados para o gráfico de áudio:', chartData); 

                plotarGrafico(chartData, chartLabels, 'audioChart');
                enviarParaFFT(channelData, buffer.sampleRate);
                resolve();
            });
        };
        reader.readAsArrayBuffer(blob);
    });
}

function stopCountdown() {
    clearInterval(timerInterval);
    timerDisplay.textContent = "";
}

function startCountdown(seconds) {
    let remaining = seconds;
    timerDisplay.textContent = `Tempo restante: ${remaining}s`;

    timerInterval = setInterval(() => {
        remaining--;
        timerDisplay.textContent = `Tempo restante: ${remaining}s`;
        if (remaining <= 0) {
            clearInterval(timerInterval);
            timerDisplay.textContent = "";
        }
    }, 1000);
}

recordBtn.onclick = async function() {
    equalizeBtn.disabled = true;
    testeBtn.disabled = true;
    window.teste = false;

    if (!recording) {
        info.innerHTML = "Iniciando gravação...";
        await zerarGrafico();

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            statusDiv.textContent = "Seu navegador não suporta captura de áudio.";
            return;
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ 
                audio: { sampleRate: 48000 } 
            });
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];
            mediaRecorder.ondataavailable = e => {
                if (e.data.size > 0) audioChunks.push(e.data);
            };
            mediaRecorder.onstop = async () => {
                const audioBlob = new Blob(audioChunks, { type: `audio/wav` });
                statusDiv.innerHTML = '';
                stopCountdown();
                await uploadAudio(audioBlob);
                criarGraficoAudio(audioBlob); 
            };

            mediaRecorder.start();
            recording = true;
            recordBtn.textContent = "Parar Gravação";
            statusDiv.textContent = "Gravando...";
            startCountdown(TEMPO_MAXIMO);

            timer = setTimeout(() => {
                if (recording) {
                    mediaRecorder.stop();
                    recording = false;
                    recordBtn.textContent = "Iniciar Gravação";
                    statusDiv.textContent = `Gravação encerrada (${TEMPO_MAXIMO}s).`;
                    stopCountdown();
                }
            }, TEMPO_MAXIMO * 1000);
        } catch (err) {
            statusDiv.textContent = "Erro ao acessar o microfone: " + err;
        }
    } else {
        // Parar gravação manualmente
        info.innerHTML = "Finalizando gravação...";
        mediaRecorder.stop();
        recording = false;
        recordBtn.textContent = "Iniciar Gravação";
        statusDiv.textContent = "Gravação encerrada.";
        clearTimeout(timer);
        stopCountdown();
    }
};

audioFileInput.onchange = async function(e) {
    const file = e.target.files[0];
    if (!file) return;
    info.innerHTML = 'Processando arquivo enviado...';
    await uploadAudio(file, null, true);
    criarGraficoAudio(file);
};

async function uploadAudio(blobOrFile, nome = null, isFile = false) {
    info.innerHTML = 'Enviando áudio para o servidor...';
    const fileName = nome || '';
    let file;
    if (isFile) file = blobOrFile;
    else file = new File([blobOrFile], `${fileName}.wav`, { type: `audio/wav` });   

    const formData = new FormData();
    formData.append('audioFile', file);
    formData.append('formato', 'wav');

    await fetch('upload.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(async result => {
        if (result.sucesso) {
            let idName;

            statusDiv.innerHTML += `<div class=\"msg-success\">Áudio salvo com sucesso!</div>
                <span class=\"msg-file\">Arquivo: <b>${result.arquivo}</b></span>`; 

            if(nome == null) {
                window.ultimoArquivo = await result.arquivo;
                idName = 'previewOriginal';
            } else {
                idName = 'previewEditado';
            }    
            
            let oldAudio = document.getElementById(idName);

            if (oldAudio) {
                oldAudio.pause();
                oldAudio.parentNode.removeChild(oldAudio);
                const links = document.getElementsByTagName('a');
                const sucess = document.getElementsByClassName('msg-success');
                const fileMsgs = document.getElementsByClassName('msg-file');

                for(let i=0; i<links.length; i++) {
                    if(i > 0 && i < links.length - 1) links[i].parentNode.removeChild(links[i]);
                    if(i > 0 && i < sucess.length - 1) sucess[i].parentNode.removeChild(sucess[i]);
                    if(i > 0 && i < fileMsgs.length - 1) fileMsgs[i].parentNode.removeChild(fileMsgs[i]);
                }
            }

            const audio = document.createElement('audio');
            audio.id = idName;
            audio.controls = true;
            audio.src = `audios/${result.arquivo}?v=${new Date().getTime()}`; // Evita cache
            statusDiv.appendChild(audio);        
        } else {
            statusDiv.innerHTML += `<div class=\"msg-error\">Falha ao enviar áudio.</div>`;
        }
    })
    .catch(() => {
        statusDiv.innerHTML += `<div class=\"msg-error\">Erro ao enviar áudio.</div>`;
    });
}

equalizeBtn.onclick = async function() {
    if(!window.ultimoArquivo) {
        alert('Nenhum arquivo disponível para equalização. Por favor, grave um áudio primeiro.');
        return;
    } else {
        equalizeBtn.disabled = true;
        recordBtn.disabled = true;
        testeBtn.disabled = true;
        info.innerHTML = "Iniciando equalização...";
        const bandas = coletarBandas();
        await realizarIFFT(bandas);
    }
}

function criarBandaBox() {
    const bandaDiv = document.createElement('div');
    bandaDiv.className = 'banda-box';
    bandaDiv.innerHTML = `
        <label>Freq. Inicial <input type="number" value="0" class="banda-inicial" min="0" step="1" required></label>
        <label>Freq. Final <input type="number" value="0" class="banda-final" min="0" step="1" required></label>
        <label>
            <select class="banda-tipo" style="width:80px;">
                <option value="ganho">Ganho</option>
                <option value="distorcao">Distorção</option>
            </select>
            <input type="number" value="0" class="banda-ganho" step="any" required>
        </label>
        <button type="button" class="btn-excluir-banda" title="Excluir">&times;</button>
    `;
    bandaDiv.querySelector('.btn-excluir-banda').onclick = function() {
        bandaDiv.remove();
    };
    return bandaDiv;
}

if (addBandsBtn && bandsContainer) {
    addBandsBtn.addEventListener('click', function() {
        bandsContainer.appendChild(criarBandaBox());
    });
}

function coletarBandas() {
    const bandas = [];
    if (!bandsContainer) return bandas;

    bandsContainer.querySelectorAll('.banda-box').forEach(div => {
        const inicial = div.querySelector('.banda-inicial').value;
        const final = div.querySelector('.banda-final').value;
        const ganho = div.querySelector('.banda-ganho').value;
        const tipo = div.querySelector('.banda-tipo').value;

        if (inicial && final && ganho) {
            bandas.push({
                inicio: Number(inicial),
                fim: Number(final),
                valor: Number(ganho),
                tipo: tipo
            });
        }
    });

    return bandas;
}

testeBtn.onclick = async function() {
    window.teste = true;
    window.ultimoArquivo = 'teste_' + Date.now();
    statusDiv.innerHTML = '';
    testeBtn.disabled = true;
    equalizeBtn.disabled = true;
    recordBtn.disabled = true;
    info.innerHTML = "Modo teste senoide ativado.";
    await zerarGrafico();

    const frequencia1 = document.getElementById('freq').value || 10; // Frequência da senoide em Hz
    const frequencia2 = document.getElementById('freq2').value || 30; // Frequência da senoide em Hz  
    const amostragem = document.getElementById('tx').value || 300; // Taxa de amostragem em Hz
    const duracao = document.getElementById('tempo').value || 1; // Duração em segundos
    const totalAmostras = amostragem * duracao;
    let dados = [0];
    let labels = [0];

    if(amostragem > 0) {
        for (let n = 1; n < totalAmostras; n++) {
            const senoide1 = Math.sin(2 * Math.PI * frequencia1 * (n / amostragem));
            // Adiciona ruído gaussiano à senoide 2
            const ruido = (Math.random() * 2 - 1) * 0.2; // Ruído entre -0.2 e 0.2
            const senoide2 = Math.sin(2 * Math.PI * frequencia2 * (n / amostragem));
            const senoide = senoide1 + senoide2;
            dados.push(senoide);
            labels.push(n);
        }
    }

    audioInfoContent.innerHTML = `
        <b>Duração:</b> ${duracao}s<br>
        <b>Período:</b> ${((1 / amostragem) * 1000).toFixed(3)} ms<br>
        <b>Amostragem:</b> ${amostragem} Hz<br>
        <b>Nº de amostras:</b> ${totalAmostras}<br>
    `;

    await enviarParaFFT(dados, amostragem);
    plotarGrafico(dados, labels, 'audioChart');
    equalizeBtn.disabled = false;
    equalizeBtn.click();
    info.innerHTML = "Simulação finalizada";
    await fetch('folders.php', { method: 'GET' });    
    recordBtn.disabled = false;
    testeBtn.disabled = false;
}