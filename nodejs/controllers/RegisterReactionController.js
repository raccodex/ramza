const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const RegisterReactionController = async (ctx, data, io,socket) => {
  let user_id = ctx.userHashUserId[data.user_id]
  var iterator = ctx.reactions_types.keys();
  var reactions_keys = Object.keys(ctx.reactions_types);
  var types = ['messages'];
  var response = '';
  
  if (!data.id || !data.reaction || !data.type || !reactions_keys.includes(data.reaction.toString()) || !types.includes(data.type.toString())) {
      var response = {status: 400};
  }
  if (response === '') {
      if (data.type === 'messages') {
          message = await funcs.Wo_GetMessageByID(ctx,data.id);
          if (message && message !== undefined) {
              if (await funcs.Wo_IsReacted(ctx,data.id,'message','',user_id) > 0) {
                  await ctx.wo_reactions.destroy({
                      where: {
                          message_id: data.id,
                          user_id: user_id
                      },
                      raw: true
                  });
              }
              await ctx.wo_reactions.create({
                  user_id: user_id,
                  message_id: data.id,
                  reaction: data.reaction,
              })
              response = {status: 200,
                          reactions: await funcs.Wo_GetPostReactions(ctx,data.id, col = "message"),
                          id: data.id}
              if (message.group_id > 0) {
                  for (let client of Object.keys(io.sockets.adapter.rooms["group" + message.group_id].sockets)) {
                      await io.to(client).emit('register_reaction', response);
                  }
              }
              else{
                  var to_id = message.from_id
                  if (user_id != message.to_id) {
                      var to_id = message.to_id
                  }
                  await io.to(to_id).emit('register_reaction', response);
                  let remainingSameUserSockets = []
                  if (ctx.userIdSocket[ctx.userHashUserId[data.user_id]]) {
                      remainingSameUserSockets = ctx.userIdSocket[ctx.userHashUserId[data.user_id]].filter(d => d.id != socket.id)
                  }
                  for (userSocket of remainingSameUserSockets) {
                      await userSocket.emit('register_reaction', response);
                  }
              }
              await socket.emit('register_reaction', response);
          }
      }
  }
};

module.exports = { RegisterReactionController };